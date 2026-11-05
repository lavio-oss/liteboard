# Phase 7: Năng lực ingest — sửa đường auth/ACL của broker, đo và đóng gate tải

> Trạng thái: Bước 2, 3, 4, 5 đã triển khai; Bước 0 đã có harness (`bench/`); Bước 7 chạy một phần (L0, L1 đủ 3 lượt, L2 một lượt, xem mục "Kết quả gate"). Bước 1 và 6 chưa làm.
> Thuộc kế hoạch: [docs/plan.md](../../../plan.md) — Phase 2 (L0 gate), Phase 7 (queue, backpressure, scaling), Phase 9 (benchmark), mục 12 (Benchmark Plan).
> Tầng kiến trúc: **Mosquitto + mosquitto-go-auth, PostgreSQL (view MQTT auth/ACL), Redis cache, `telemetry-service`**
> Liên quan: [telemetry-flow.md](../current/telemetry-flow.md), [devices_access_token.md](../../devices/current/devices_access_token.md), [phase-8-quotas-and-rate-limiting.md](../../devices/plan/phase-8-quotas-and-rate-limiting.md)

---

## 1. Bối cảnh

Một đợt benchmark local ngày 2026-11-05 (commit `16036f2`, máy 8 CPU / 14,9 GB RAM, Docker Desktop 7,2 GB) so sánh liteboard với ThingsBoard CE 4.2.1 cho thấy liteboard không chịu được L2 (500 device). Mỗi cấu hình chỉ đo một lần; số liệu dưới đây là kết quả của đúng môi trường đó, không phải năng lực sản phẩm.

QoS1, 1 msg/s mỗi device, kết nối toàn bộ device xong mới bắt đầu gửi:

| Device | Liteboard (cấu hình hiện tại) | Liteboard (bcrypt cost 8 + cache auth/ACL 300 s) | ThingsBoard CE |
|---|---|---|---|
| 10 | 600 / 600 lưu | — | 600 / 600 |
| 100 | 6020 / 6020 | 6000 / 6000 | 6062 / 6062 |
| 500 | 20 / 15000 | 1596 / 30000 | 30000 / 30000 |
| 1000 | không chạy | 21 / 60000 | 60000 / 60000 |

Dữ kiện chính:
- Ở các lượt hỏng, `telemetry-service` gần như rảnh (CPU 0,6–5,9%), trong khi Mosquitto dùng 165–344% CPU và PostgreSQL 86–146%. Dữ liệu không tới được service, chứ không phải service xử lý không kịp.
- `v_mqtt_acls` là UNION ALL nhiều nhánh join `device_credentials`, `devices`, `projects`, `tenants`. EXPLAIN ANALYZE: lập kế hoạch ~51 ms, thực thi ~9,75 ms mỗi lần gọi.
- Sau khi generator đã dừng, PostgreSQL vẫn liên tục chạy truy vấn ACL (`rw = 2`) khoảng 90 ms mỗi lần, trong khi số sample không tăng.
- Hạ bcrypt cost từ 12 xuống 8 làm kết nối nhanh hơn ~5 lần (500 device: 125,9 s → 24,3 s) nhưng không giảm mất mẫu.
- Log broker không có keepalive timeout hay `not authorised`.
- Số sample lưu ở các lượt hỏng nặng nhất dừng quanh 20, trùng `max_inflight_messages` / `MQTT_RECEIVE_MAXIMUM` = 20.
- Hai payload lẽ ra bị từ chối theo [telemetry-flow.md mục 5.1](../current/telemetry-flow.md) vẫn được lưu: `occurred_at` năm 2030 và `schema_version` = 9, trên device gắn profile version 1 có `fields: []`.

**Giả thuyết (chưa kiểm chứng):** mosquitto-go-auth gọi PostgreSQL đồng bộ trên luồng đơn của broker cho mỗi lần cache miss của kiểm tra quyền publish. Với truy vấn ~60–90 ms, broker đứng hình. Nó không đọc message mới, không chuyển message cho subscriber, không nhận PUBACK. Message bị mất vì client bỏ message chưa có PUBACK khi ngắt, hoặc vì hàng đợi cho subscriber vượt `max_queued_messages` = 1000 và broker bỏ âm thầm. Chưa phân biệt được hai đường này.

## 2. Mục tiêu

1. Xác nhận hoặc bác bỏ giả thuyết ở mục 1 bằng thí nghiệm có số đo.
2. Đưa đường auth/ACL ra khỏi vai trò điểm nghẽn để L2 (500 device) đạt: không mất dữ liệu không giải thích được, có đối soát.
3. Mọi trường hợp broker bỏ message phải có số đo và cảnh báo, không còn mất âm thầm.
4. Đóng gate L0 và L1 chính thức theo mẫu báo cáo ở plan mục 12, trước khi mở rộng tính năng data plane.
5. Xác minh và xử lý hai chỗ lệch hợp đồng payload.

Ngoài phạm vi: đổi broker sang EMQX/VerneMQ, đổi định dạng credential (username = `device_id`), L4/L5. Chỉ xem xét lại nếu các bước dưới đây không đủ.

## 3. Các bước

Làm theo thứ tự. Mỗi bước đo trước và sau; mỗi lần chỉ đổi một thứ.

### Bước 0. Đưa harness benchmark vào repo

- Thư mục `bench/` gồm:
  - generator MQTT (asyncio; chạy trong container Linux, vì event loop trên Windows chỉ giữ được ~512 socket);
  - lệnh tạo device qua API;
  - đếm sample để đối soát;
  - lấy mẫu `docker stats`;
  - xuất báo cáo theo mẫu ở plan mục 12.
- Generator đếm PUBACK nhận được, tách "gửi" với "broker đã nhận".
- Dọn device tạo ra sau mỗi lượt, để bảng `devices` không phình làm sai lệch số đo.
- Ghi chú môi trường Windows: `host.docker.internal:5432` có thể trỏ vào một PostgreSQL native nếu máy có cài; khi chạy benchmark nên đặt `MQTT_PG_HOST=postgres`.

Gate: một lệnh chạy được L0 và in ra báo cáo đúng mẫu.

### Bước 1. Thí nghiệm xác nhận (không sửa code)

1. Đọc bộ đếm `$SYS` về message bị bỏ trước và sau mỗi lượt.
2. Chạy 500 device trong 5 phút, ghi số sample lưu theo từng 10 giây.
3. Làm nóng cache auth/ACL (mỗi device publish 1 message, chờ, rồi mới chạy tải) và chạy lại 500 device.
4. Xác nhận trong mã nguồn hoặc tài liệu của mosquitto-go-auth: lời gọi backend có chạy đồng bộ trên luồng broker không, và kết quả bị từ chối có được cache không.

Gate: có kết luận ghi lại rằng điểm nghẽn là ACL hay là thứ khác, và message mất theo đường nào.

### Bước 2. Truy vấn ACL rẻ

- Migration mới (forward-only) thay `v_mqtt_acls` bằng một lần join duy nhất. Ghép với danh sách hậu tố topic cố định (`telemetry`, `heartbeat`, `commands`, `commands/ack`, các topic gateway) và lọc nhánh gateway bằng điều kiện.
- Giữ nguyên ngữ nghĩa: cùng tập `(token_hash, topic, rw)` cho mọi trạng thái của credential, device, project và tenant. Viết test so sánh kết quả view cũ và mới trên dữ liệu có đủ các trạng thái.
- Nếu vẫn chậm, phương án tiếp theo là bảng `mqtt_acl_entries(token_hash, topic, rw)` có index, đồng bộ từ Laravel. Phương án này cần test mọi luồng đổi trạng thái: tạo, duyệt, từ chối, khóa device, xoay và thu hồi token, đổi trạng thái project/tenant.

Gate: EXPLAIN ANALYZE truy vấn ACL dưới 2 ms (gồm lập kế hoạch); test tương đương ngữ nghĩa và tenant isolation đều qua.

**Kết quả (2026-11-05, PostgreSQL 9806 device, `plan_cache_mode = force_custom_plan`, chạy trên máy đang tải):**

| View | Ép `::char(64)` | Lập kế hoạch | Thực thi |
|---|---|---|---|
| Cũ | Không | 20–166 ms | 185–353 ms (seq scan `devices`) |
| Cũ | Có | 41–110 ms | 2–34 ms |
| Mới | Không | ~2 ms | 60–139 ms |
| Mới | Có | ~1,4 ms (lần đầu 7,5 ms) | ~0,3 ms |

`EXCEPT` hai chiều giữa view cũ và mới: 0 dòng. Gate < 2 ms đạt ở các lần gọi nóng (~1,7 ms), sát ngưỡng; lần đầu 7,8 ms. Số đo chỉ một phiên, chưa lặp 3 lần.

### Bước 3. TTL cache auth/ACL

- Tăng `auth_opt_auth_cache_seconds` / `auth_opt_acl_cache_seconds` từ 5 lên khoảng 60 giây, chỉ sau khi đã đo bước 2.
- Ghi vào [devices_access_token.md](../../devices/current/devices_access_token.md) độ trễ thu hồi tối đa mới: token bị thu hồi hoặc device bị khóa vẫn publish được trong tối đa bằng TTL.

Gate: L2 chạy lại, có số đo so với trước khi đổi TTL.

#### Ghi chú thực hiện bước 2 và 3 (dùng cho báo cáo)

Đã làm (2026-11-05):

- Migration `2026_11_05_000001_simplify_mqtt_acls_view.php`: `v_mqtt_acls` còn một lần join `v_mqtt_users` với danh sách hậu tố hằng (thay 8 nhánh UNION ALL), thêm cột `device_id`. Test: `backend/tests/Feature/MqttAclViewTest.php`.
- `mosquitto.conf` và `mosquitto-plain.conf`: userquery và aclquery ép `encode(...)::char(64)`; aclquery của listener plain join `devices` qua `a.device_id` thay vì join lại `v_mqtt_users`.
- TTL `auth_opt_auth_cache_seconds` / `auth_opt_acl_cache_seconds`: 5 -> 30 giây (đã cân nhắc 60, chọn 30 để giữ độ trễ thu hồi thấp hơn). Độ trễ thu hồi tối đa mới: 30 giây, áp dụng cả cho công tắc plaintext.

Nguyên nhân đã xác nhận bằng EXPLAIN: (1) so sánh `char(64)` với `text` làm PostgreSQL bỏ unique index và seq scan (thực thi 60-350 ms); (2) view cũ có ~32 lần join phải lập kế hoạch lại ở mỗi lần gọi (20-166 ms). Hai nguyên nhân độc lập; số đo ở bảng trên cho thấy cần cả hai thay đổi để về ~1,7 ms. Chưa tách riêng đóng góp trên hệ thống chạy thật vì chưa có harness L2.

Ép `::char(64)`:

- Ưu: đã đo (thực thi ~0,3 ms so với 60-139 ms); chỉ đổi config, không migration; ngữ nghĩa không đổi vì SHA-256 hex luôn 64 ký tự; áp dụng cho cả đường auth lẫn ACL; lợi ích tăng theo số device.
- Nhược: dễ bị xóa nhầm vì trông thừa (chưa có comment trong conf); phụ thuộc kiểu cột, đổi `token_hash` sang `varchar`/`text` thì phải đổi cùng; test SQLite không bắt được mất index; là cách vá, cách gốc là đổi kiểu cột bằng migration (đụng schema, rủi ro cao hơn).

View mới:

- Ưu: ít join nên lập kế hoạch nhanh (~1,4 ms); cùng tập `(token_hash, topic, rw)` (EXCEPT hai chiều = 0 dòng trên 9806 device); có test cho đủ trạng thái credential/device/project/tenant và tenant isolation; forward-only.
- Nhược: cột `gateway_only` chỉ để tương thích SQLite (không có `VALUES ... AS s(cols)`); cần chạy migration trước khi restart broker, vì config plain dùng cột `device_id` mới.

TTL 30 giây:

- Ưu: ít lượt truy vấn PostgreSQL hơn ở mỗi cache miss, giảm nghẽn luồng đơn của broker.
- Nhược: token bị thu hồi hoặc device bị khóa vẫn publish được tối đa 30 giây (gấp 6 lần trước); cần bên bảo mật xác nhận. Chưa đo tác động lên L2.

Chưa làm / hạn chế số liệu: số đo EXPLAIN chạy một phiên, máy đang tải, chưa lặp 3 lần; chưa áp migration vào DB Docker; chưa có `bench/` nên gate của bước 3 (L2 trước/sau TTL) chưa chạy; chưa kiểm go-auth có dùng prepared statement không tên hay không (bước 1.4).

### Bước 4. Biến mất âm thầm thành có số đo

- Xuất bộ đếm message bị broker bỏ, queue depth của session `telemetry-service`, độ trễ truy vấn auth/ACL.
- Đặt cảnh báo khi số message bị bỏ lớn hơn 0 hoặc queue depth vượt ngưỡng.

Gate: tái hiện được một lượt quá tải và thấy cảnh báo bật.

### Bước 5. Hợp đồng payload

- Xác minh trong `telemetry-service` vì sao `occurred_at` năm 2030 và `schema_version` = 9 được lưu trên device có profile `fields: []`.
- Nếu là lỗi, sửa và thêm test. Nếu là hành vi chủ ý với profile rỗng, cập nhật [telemetry-flow.md](../current/telemetry-flow.md) cho khớp.

Gate: code và spec khớp nhau, có test cho cả hai case.

**Kết luận bước 5 (2026-11-05):** không phải lỗi. `PostgresTelemetryIngestor` coi Profile Version có `fields` rỗng (profile mặc định tự tạo) như Device không có profile (`telemetry_ingest.py`, biến `validating_profile`). Nhánh này gọi `inspect_unprofiled_telemetry`: bỏ qua `occurred_at` và `schema_version` của firmware, `occurred_at` luôn là thời điểm nhận. Hai payload trong benchmark vì vậy được lưu đúng thiết kế. Spec mục 5.2 ghi sai (nói reject), nên đã sửa spec; mục 5.2 cũng đã được chỉnh các dòng về `values` và kiểu metric cho khớp code. Test mới: `test_empty_profile_ignores_client_occurred_at_and_schema_version` và `test_profile_with_fields_rejects_far_future_occurred_at_and_wrong_schema_version` trong `telemetry-service/tests/test_telemetry_ingest.py`.

Rủi ro sản phẩm cần chủ sản phẩm xác nhận: device mới tạo có profile rỗng nên không được kiểm tra thời gian hay phiên bản, và đồng hồ sai của firmware bị che đi vì `occurred_at` luôn là thời điểm server nhận.

### Bước 6. Chi phí đăng nhập (tùy chọn)

- `device_credentials` đã lưu `token_hash` (SHA-256 của token), nên bcrypt cost 12 cho `mqtt_password_hash` không thêm bảo vệ đáng kể nếu DB bị lộ. Token dài 20 ký tự ngẫu nhiên (~100 bit). Đề xuất hạ cost hoặc dùng hasher nhanh hơn mà go-auth hỗ trợ.
- Cần người phụ trách bảo mật duyệt lập luận trước khi đổi.

Gate: kết nối 1000 device dưới một ngưỡng được thống nhất trước; có quyết định bảo mật được ghi lại.

### Bước 7. Đóng gate L0 / L1 / L2

- Chạy L0 (10 device, 5 phút), L1 (100 device, 10 phút), L2 (500 device, 15 phút) theo plan mục 12. Mỗi mức lặp ít nhất 3 lần, lấy median.
- Lưu báo cáo vào `docs/` kèm commit, cấu hình, đối soát, p50/p95/p99, tài nguyên và điểm nghẽn kế tiếp.

Gate: L0 và L1 đạt pass criteria của plan; L2 có số đo và không có mất mẫu không giải thích được.

#### Kết quả gate (2026-11-05, commit 16036f2 + thay đổi chưa commit của phase này)

Môi trường: 8 CPU / 14,9 GB, Docker Desktop 7,2 GB; Mosquitto 2.0.15 (config của repo, TTL cache 30 giây, bcrypt cost 10); PostgreSQL và `telemetry-service` giới hạn 2 CPU / 1 GB; QoS 1, payload phẳng khoảng 150 byte, 1 msg/s mỗi device; generator chạy trong container cùng Docker network, kết nối xong toàn bộ mới gửi. Lệnh: `python bench/run.py L0|L1|L2 --runs 3`. DB còn dữ liệu từ lần benchmark trước (9806 device, hơn 44 nghìn mẫu). Chạy dừng thủ công sau lượt L2 đầu tiên theo yêu cầu, nên L2 chỉ có 1 lượt, không có median.

| Mức | Lượt | Gửi / PUBACK / lưu | Mất | e2e p50 / p95 / p99 (ms) | Trong service p50 / p95 / p99 (ms) | Hàng đợi broker tối đa | Broker bỏ |
|---|---|---|---|---|---|---|---|
| L0: 10 device, 5 phút | 3, median | 3000 / 3000 / 3000 | 0 | 15,0 / 30,4 / 38,3 | 9,8 / 24,6 / 28,4 | 53–63 | 0 |
| L1: 100 device, 10 phút | 3, median | 60000 / 60000 / 60000 | 0 | 109,2 / 292,0 / 441,9 | 28,3 / 61,3 / 101,3 | 55 / 105 / 151 | 0 |
| L2: 500 device, 15 phút | 1 | 450000 / 450000 / 304819 | 145181 (32,3%) | 2425 / 4631 / 5946 | 33,2 / 70,6 / 104,7 | 1093 | 145181 |

CPU trung bình (mosquitto / telemetry-service / postgres): L0 khoảng 4% mỗi thành phần; L1 14–17% / 20% / 27–28%; L2 57% / 65% / 87% (một lõi = 100%, mỗi container giới hạn 2 lõi). Bộ nhớ `telemetry-service` tối đa 38–48 MB ở mọi mức. Không có `not authorised` hay keepalive timeout ở lượt nào. Pha kết nối: 2,4 s (10 device), 23,6 s (100), 118,8 s (500), không lượt nào lỗi kết nối.

Kết luận:

- L0 đạt: không mất mẫu, đối soát khớp, hàng đợi và bộ nhớ có giới hạn. Chưa kiểm "reconnect succeeds" vì harness chưa có thử nghiệm ngắt kết nối. Chưa có thử nghiệm nguồn lỗi (fault injection).
- L1 đạt: ổn định cả ba lượt, không mất mẫu, không có tăng trưởng hàng đợi không giới hạn. Hàng đợi tối đa tăng qua ba lượt (55 → 105 → 151), cần theo dõi nhưng chưa phải xu hướng đã chứng minh.
- L2 không đạt tiêu chí "không mất mẫu", nhưng mất mẫu **đã giải thích được**: broker đã PUBACK đủ 450000 message nhưng bỏ 145181 message khi gửi cho session của `telemetry-service`, vì hàng đợi vượt `max_queued_messages 1000`. Số bị bỏ (`$SYS/broker/publish/messages/dropped`) bằng đúng số mẫu thiếu. Thông lượng lưu ổn định khoảng 330 msg/s suốt lượt (từ giây 175 đến 973, từ 22206 lên 285918 mẫu), thấp hơn 500 msg/s đưa vào.
- Giả thuyết ở mục 1 (auth/ACL làm broker đứng hình) **không đúng với số đo này**: không có lỗi auth, broker không nghẽn (CPU trung bình 57%). Nghẽn nằm ở tốc độ tiêu thụ của `telemetry-service` (một MQTT client, cửa sổ nhận 20, pool PostgreSQL 5). Việc này khớp mục 4 của tài liệu. Chưa kiểm chứng đây là cửa sổ nhận hay pool; cần thí nghiệm từng biến.
- Gate của Bước 4 đạt: lượt L2 bật cả hai cảnh báo (`messages_dropped`, `queue_depth_high`) và log ERROR tương ứng.
- Truy vấn ACL (Bước 2) đã loại khỏi danh sách nghẽn ở các mức đã đo. So sánh với lần benchmark trước chỉ mang tính tham khảo: thời lượng, TTL cache và điều kiện khác nhau, mỗi lần chỉ chạy một lượt ở L2.

Việc đo tiếp theo (một biến mỗi lần, mỗi lần chạy lại L2): (1) `MQTT_RECEIVE_MAXIMUM` 20 → 100, không đổi code; (2) thêm kích thước pool PostgreSQL (hiện hằng số 5 trong `telemetry-service/app/main.py`); (3) ghi theo lô. Chưa thực hiện.

Dọn dẹp: lượt L2 bị dừng giữa chừng để lại 344 device `bench-*`, đã xóa bằng SQL; bảng `devices` về lại 9806 dòng.

## 4. Sau phase này

Khi broker hết là điểm nghẽn, điểm kế tiếp dự kiến là `telemetry-service`: một MQTT client, pool PostgreSQL 1–5, cửa sổ nhận 20. Việc scale (MQTT 5 shared subscription, ghi theo lô, nhiều worker) thuộc Phase 7 của plan và chỉ làm khi số đo của bước 7 chỉ ra.

Nếu bước 2 và 3 không đủ, xem lại hai phương án lớn đã gác:
- Đổi credential thành username = `device_id` để ACL theo pattern, không gọi DB ở mỗi publish. Đổi hợp đồng credential, phải sửa firmware.
- Đổi sang broker đa luồng. Thay đổi hạ tầng lớn, cần review license như plan mục 4 và 9 đã nêu.
