# Kế hoạch nghiên cứu MQTT IDS cho LiteBoard

> Trạng thái: **Research / POC**. Chưa thuộc luồng ingest, release hoặc cam kết bảo mật của LiteBoard. Chỉ xem xét triển khai worker tùy chọn sau khi POC chạy được trên traffic LiteBoard và vượt các cổng đánh giá trong tài liệu này.

## 1. Quyết định kiến trúc

- Màn theo dõi telemetry và cảnh báo nghiệp vụ là chức năng của LiteBoard, theo IT-4 (message trace, REST, WebSocket) và IT-5 (rule, alert).
- MQTT traffic IDS là một năng lực bảo mật riêng. Không gộp model, packet capture hay xử lý IDS vào callback/ingestion path của `telemetry-service`.
- Giai đoạn hiện tại chỉ xây POC offline để tái lập và đánh giá paper với MQTT-IoT-IDS2020. Wireshark dùng để khám phá PCAP thủ công; pipeline có thể chạy lặp lại phải dùng script/CLI trích xuất feature đã version hóa (ví dụ TShark hoặc extractor phù hợp).
- Nếu POC hữu ích trên traffic LiteBoard, triển khai `security-monitor` như worker tùy chọn, tách tiến trình và tài nguyên khỏi `telemetry-service`. Worker tạo `SecurityFinding`; LiteBoard có thể hiển thị finding trong khu vực cảnh báo, nhưng phải phân biệt rõ với `Alert` do rule thiết bị tạo.
- Chạy shadow mode trước. IDS không tự khóa Device, thu hồi Access Token, gửi command hoặc thay đổi ACL. Hành động ứng phó cần một policy và luồng phê duyệt riêng sau khi có số đo false positive đáng tin cậy.

LiteBoard là hệ thống multi-tenant: cấu hình/model dùng chung và worker điều phối là dữ liệu/vai trò trung tâm. Finding gắn được với Device/Project phải xác định tenant qua danh tính đã xác thực và dữ liệu control plane phía server; không lấy tenant từ payload, IP hay topic do client gửi. Sự kiện không quy được về tenant phải được giữ ở phạm vi vận hành nền tảng, không gán tùy đoán cho tenant. PCAP và dữ liệu mạng thô không đưa vào API/UI tenant.

## 2. Phân biệt dữ liệu cần theo dõi

| Luồng | Dữ liệu quan sát | Mục tiêu | Nơi xử lý |
|---|---|---|---|
| Telemetry ứng dụng | MQTT PUBLISH đã tới subscription, payload sau giải mã, kết quả schema/persistence | Theo dõi thiết bị và điều kiện vận hành | `telemetry-service`, API và màn telemetry LiteBoard |
| Broker/security audit | Kết nối, xác thực, subscribe/ACL, disconnect và thông tin client mà broker ghi nhận | Phát hiện brute-force hoặc hành vi bất thường quanh broker | Adapter/worker security riêng |
| Network/flow | Thời gian, kích thước, hướng, địa chỉ/port và đặc trưng flow; có thể gồm packet metadata | Phát hiện scan và mẫu tấn công ở tầng mạng | Sensor/extractor tách biệt tại broker/host hoặc môi trường capture được kiểm soát |

`telemetry-service` hiện nhận telemetry/heartbeat để kiểm tra topic/JSON và cập nhật presence; nó không phải sensor mạng và không nhìn thấy CONNECT thất bại, scan hoặc toàn bộ metadata kết nối. Vì vậy không thể lấy riêng subscriber PUBLISH làm nguồn dữ liệu đầy đủ cho paper IDS. Xem [plan tổng thể](../../plan.md) để biết trạng thái Data Plane.

LiteBoard hiện dùng MQTT TLS trên cổng `8883`. Không hạ TLS hoặc mở plaintext để giúp model đọc traffic. Capture trên đường truyền TLS không tự cung cấp MQTT payload đã giải mã; nếu cần feature ở tầng broker, hãy thu event tại điểm broker kết thúc TLS hoặc dùng audit/flow source phù hợp. Chỉ lưu feature cần thiết; giới hạn quyền đọc, retention và export của PCAP/log thô.

## 3. Phạm vi POC

### POC A — Tái lập paper trên MQTT-IoT-IDS2020

Dataset được mô tả là mạng MQTT mô phỏng gồm broker, 12 sensor, camera mô phỏng và attacker; có normal operation, aggressive scan, UDP scan, Sparta SSH brute-force và MQTT brute-force. Tác giả phát hành PCAP và feature ở ba mức packet, unidirectional flow và bidirectional flow. Đây là benchmark mô phỏng có ích cho tái lập nghiên cứu, không phải bằng chứng rằng model đã tổng quát hóa sang môi trường LiteBoard.

Các bước:

1. Ghi rõ phiên bản dữ liệu, checksum, license/điều khoản sử dụng, schema feature và mapping nhãn; không commit PCAP hoặc dữ liệu nhạy cảm vào Git.
2. Tái lập preprocessing và baseline paper trên các feature packet/uni-flow/bi-flow. Ghi lại phiên bản thư viện, seed, cấu hình, thời gian chạy và cách xử lý missing value/class imbalance.
3. Tách hai mục tiêu đánh giá: reproduction theo protocol paper và đánh giá tổng quát hóa bằng split theo capture/session/scenario khi dữ liệu cho phép. Không dùng random row split làm bằng chứng duy nhất; các record từ cùng một capture có thể chia sẻ đặc điểm môi trường. Đây là biện pháp kiểm soát rủi ro leakage, không phải khẳng định paper chắc chắn bị leakage.
4. Báo cáo confusion matrix, precision/recall/F1 theo từng lớp, macro-F1 và PR-AUC phù hợp; không kết luận chỉ từ accuracy, nhất là khi lớp mất cân bằng.
5. So sánh model với baseline đơn giản/rule-based và đánh giá chi phí trích feature, scoring, CPU, RAM và thời gian phát hiện.

Repository mã nguồn của tác giả dùng split 75/25 với `train_test_split` và thêm `StratifiedKFold` 5 fold; có thể dùng để đối chiếu reproduction, sau đó báo cáo riêng kết quả split theo capture/session. [Mã nguồn classification.py](https://github.com/AbertayMachineLearningGroup/MQTT_ML/blob/master/classification.py).

### POC B — Kiểm tra khả năng áp dụng vào LiteBoard

Chỉ làm sau khi POC A chạy lặp lại được. Tạo traffic lành tính và kịch bản tấn công có kiểm soát trong môi trường cô lập; không chủ động thử brute-force/scan trên broker production hoặc mạng không thuộc quyền kiểm soát.

1. Liệt kê threat cần phát hiện và nguồn có thể quan sát cho từng threat: broker audit, network flow, host sensor hoặc dữ liệu sau broker.
2. Kiểm tra feature availability dưới topology TLS hiện tại. Nếu feature trong dataset không thu được trên deployment thật, không giả định có thể thay thế chúng bằng payload telemetry.
3. Thu traffic LiteBoard có nhãn ground-truth trong pilot cô lập, bao gồm các biến thể benign như reconnect, mất mạng, firmware retry và burst hợp lệ để đo false positive.
4. Đánh giá drift giữa dataset mô phỏng và traffic LiteBoard; không fine-tune/test trên cùng capture rồi trình bày như khả năng phát hiện traffic mới.
5. Chạy scoring offline trước, sau đó shadow mode trên event stream pilot. Shadow mode chỉ ghi score/finding, không tác động thiết bị.

## 4. Kiến trúc nếu POC vượt cổng

```text
Device -- MQTTS --> Mosquitto --> telemetry-service --> telemetry storage
                         |
                         +--> broker audit / network-flow source
                                      |
                                      v
                           security-monitor (optional)
                                      |
                          SecurityFinding + evidence reference
                                      |
                         Laravel authorization / API / broadcast
                                      |
                         LiteBoard security/alert view
```

- `telemetry-service` tiếp tục ưu tiên ingest đúng hạn và lưu telemetry đã xác thực. Nó không đợi IDS scoring hoàn tất.
- `security-monitor` đọc nguồn audit/flow nội bộ, tạo feature theo cửa sổ thời gian, chấm điểm bất đồng bộ và gắn `model_version`, `feature_version`, `score`, `confidence`/class, thời gian quan sát và reason/evidence reference.
- Finding được lưu bền vững trước khi thông báo realtime. API truy vấn lại là nguồn khôi phục sau reconnect; WebSocket chỉ là kênh đẩy nhanh.
- Nếu cần queue/event bridge, bắt đầu từ thành phần đã có và đo được trong topology. PostgreSQL là nguồn đúng cho finding; Redis/broadcast chỉ là tối ưu live delivery. Không thêm Kafka hoặc cluster chỉ vì có IDS.
- Laravel kiểm tra quyền Tenant/Project cho REST và WebSocket như các tài nguyên khác. Không cho browser subscribe trực tiếp vào broker, `$SYS` hoặc stream nội bộ.
- Có giới hạn tốc độ/buffer, backpressure, retention, dead-letter/error outcome và metric cho worker; lỗi IDS không làm ngừng nhận/lưu telemetry.

### Hợp đồng `SecurityFinding` tối thiểu

- `id`, `detected_at`, `first_seen_at`, `last_seen_at` nếu finding được gom nhóm.
- `scope`: platform hoặc tenant/project/device; scope chỉ được gán sau khi resolve identity phía server.
- `source`: broker-audit, network-flow hoặc model; `detector_version` và `feature_version`.
- `category`, `severity`, `score`/`confidence`, trạng thái shadow/triage/resolved.
- `evidence_summary` đã làm sạch và reference nội bộ tới evidence có kiểm soát; không trả raw PCAP, secret, Access Token hay payload nhạy cảm vào event UI.
- Trạng thái acknowledge/triage là dữ liệu có audit; `SecurityFinding` không dùng chung lifecycle với alert nhiệt độ/thiết bị nếu ngữ nghĩa khác nhau.

## 5. Wireshark và công cụ vận hành

- Wireshark phù hợp để một người phân tích PCAP, kiểm tra giao thức và điều tra mẫu bất thường trong POC.
- Với xử lý lặp lại, batch hoặc tích hợp worker, dùng TShark/feature extractor có cấu hình, version và output schema rõ ràng; không khởi chạy giao diện Wireshark trong container production.
- Không bật debug/packet logging rộng trên broker mặc định lâu dài nếu chưa đo dung lượng và kiểm tra dữ liệu nhạy cảm. Broker audit cần chọn đúng event, destination, retention và quyền truy cập; nó không mặc nhiên tương đương một packet capture đầy đủ.
- PCAP/log lấy từ pilot phải có mục đích, chủ sở hữu, thời gian lưu và quy trình xóa; tránh đưa IP/client identity có thể nhận diện tenant lên màn hình tenant khác.

## 6. Cổng quyết định productization

Chỉ tạo worker `security-monitor` tùy chọn và hiển thị finding trong LiteBoard khi tất cả điều kiện sau có bằng chứng:

- Reproduction có thể chạy lại, preprocessing và model artifact được version hóa; kết quả có báo cáo theo lớp và split rõ ràng.
- Có nguồn feature hợp pháp, ổn định từ deployment LiteBoard mà không giảm TLS hoặc làm chậm ingestion.
- Shadow test trên traffic LiteBoard có nhãn cho thấy precision/recall, false positive theo thiết bị/ngày, detection latency và resource overhead phù hợp với use case đã chọn.
- Có quy trình xem xét finding, quyền tenant, retention, audit và rollback model.
- Có quyết định rõ ai vận hành cảnh báo và hành động nào được phép. Giai đoạn đầu không tự động khóa Device, revoke token hay phát command.

Nếu không đạt, giữ POC như công cụ nghiên cứu/điều tra offline; không đưa model vào đường ingest hoặc gọi LiteBoard là có MQTT IDS production.

## 7. Thước đo và nghiệm thu

### Offline benchmark

- Reproduction theo packet, uni-flow và bi-flow; báo cáo cấu hình và sai khác so với kết quả tham chiếu.
- Confusion matrix, precision, recall, F1 từng lớp, macro-F1, PR-AUC phù hợp với bài toán, và kết quả split theo capture/session/scenario.
- Thời gian feature extraction/training/scoring, CPU, RAM và kích thước artifact.

### LiteBoard shadow pilot

- False positive theo Device/ngày và theo loại traffic benign.
- Recall/precision theo threat có ground-truth; phát hiện được những lớp nào và không phát hiện được lớp nào.
- Detection latency p50/p95/p99 từ event nguồn đến finding persist/broadcast.
- Mức tăng CPU/RAM, broker log/flow throughput, queue age/drop và tác động đo được lên telemetry ingestion.
- Finding không thể đọc chéo Tenant/Project; mất kết nối/reconnect không làm nhân bản finding logic; worker dừng hoặc model lỗi không làm mất telemetry.

Đặt SLO sau khi có baseline pilot, không lấy con số accuracy trong paper làm cam kết LiteBoard.

## 8. Quan hệ với các spec hiện có

- [Industrial telemetry IT-3 — MQTT ingestion và time-series persistence](../industrial-telemetry/phase-03-mqtt-ingestion-storage.md): tạo dữ liệu telemetry bền vững và contract trước.
- [Industrial telemetry IT-4 — REST, WebSocket và message trace](../industrial-telemetry/phase-04-rest-websocket-trace.md): message trace realtime thuộc chức năng telemetry của LiteBoard.
- [Industrial telemetry IT-5 — Rule, alert và webhook](../industrial-telemetry/phase-05-rules-alerts-webhook.md): alert nghiệp vụ theo rule; ML IDS không nằm trong slice này.
- [Kế hoạch tổng thể](../../plan.md): IDS vẫn là hướng nghiên cứu tùy chọn; không đổi non-goal ML của các release đầu cho đến khi có POC và quyết định productization.

## 9. Tài liệu tham khảo

- Hindy et al., [Machine Learning Based IoT Intrusion Detection System: An MQTT Case Study](https://arxiv.org/abs/2006.15340).
- University of Strathclyde, [MQTT-IoT-IDS2020 dataset description](https://pureportal.strath.ac.uk/en/datasets/mqtt-iot-ids2020-mqtt-internet-of-things-intrusion-detection-data/).
- Authors' [MQTT_ML code and run instructions](https://github.com/AbertayMachineLearningGroup/MQTT_ML).
