# Phase 6: HTTPS Device Provisioning bằng Access Token

> Mục tiêu: thiết bị mới tự đăng ký vào đúng Project khi khởi động lần đầu và nhận Access Token để kết nối MQTT.
> Tham chiếu: mô hình provisioning của ThingsBoard; contract và tenant boundary được điều chỉnh cho LiteBoard.
> Phạm vi: HTTPS bootstrap, tạo Device và cấp Access Token hiện có. Access Token là credential đăng nhập MQTT duy nhất.
> Trạng thái: Planned — chưa triển khai.
> Phụ thuộc: Laravel Control Plane, PostgreSQL, Mosquitto, simulator và ESP32 firmware.
> Roadmap: [Access Token plan](../access_token_plan.md) và [các phase tiếp theo](../future_plans/future_device_security_and_ingestion_plan.md).

## 1. Mục tiêu và tham chiếu ThingsBoard

Phase này giải quyết bước onboarding đang thiếu: hiện quản trị viên phải tạo Device trước, sau đó cấu hình token vào firmware. Sau Phase 6, một thiết bị chưa đăng ký có thể gửi thông tin enrollment qua HTTPS; Control Plane xác thực profile, tạo Device trong Project đã được gán và trả credential.

ThingsBoard dùng Device Profile có provisioning key và secret. Thiết bị gọi HTTP provisioning endpoint; khi request hợp lệ mà chưa có credential được cấp sẵn, nền tảng tạo Device và trả Access Token. LiteBoard tham khảo luồng này cho HTTPS bootstrap. Tham khảo [ThingsBoard HTTP Provisioning](https://thingsboard.io/docs/reference/http-api/provisioning/).

LiteBoard áp dụng cùng vòng đời nhưng profile thuộc Project vì Device được quản lý theo Project. V1 chỉ cấp Access Token để tái sử dụng credential, broker auth và ACL đang có.

## 2. Hiện trạng LiteBoard

Đối chiếu mã nguồn hiện tại:

- Khi quản trị viên tạo Device, Create Device gọi DevicePersistenceService; service tạo Device và gọi DeviceCredentialService để tự sinh Access Token trong cùng transaction.
- Mỗi Device có một row credential. Token được lưu dưới dạng SHA-256 hash để tra cứu và ciphertext để người có quyền xem credential.
- MQTT hiện yêu cầu username bằng Access Token và password cũng bằng Access Token. mosquitto-go-auth tra credential bằng username rồi so khớp password hash.
- PostgreSQL views chỉ cấp quyền khi Device, Project và Tenant active; topic ACL được dựng theo Device.
- Simulator và ESP32 hiện phải được cấu hình token đã cấp. Chưa có provisioning profile hoặc bootstrap endpoint.
- TLS listener 8883 đã được cấu hình trong repository. Lần đối chiếu ngày 2026-10-01 chưa xác nhận broker runtime vì Docker Engine không khả dụng và cổng 8883 không mở.

Các điểm kiểm tra chính: infrastructure/mosquitto/mosquitto.conf, infrastructure/mosquitto/acls, backend/modules/Admin/Services/Device/DevicePersistenceService.php, backend/modules/Admin/Services/Device/DeviceCredentialService.php, backend/database/migrations/2026_09_27_000002_create_device_credentials_table.php, backend/database/migrations/2026_09_27_000003_create_mqtt_views.php, simulator/app/main.py và simulator/Esp32/Base/Base.ino.

## 3. Phạm vi Phase 6

### Trong phạm vi

1. Profile provisioning do người có quyền quản lý Device của Project tạo, bật/tắt và rotate secret.
2. Public HTTPS bootstrap xác thực profile, tạo Device, phát Access Token và trả thông tin MQTT.
3. Claim chống tạo trùng, retry idempotent, giới hạn số Device theo Project và rate limit.
4. Simulator và ESP32 mẫu bootstrap một lần, lưu Access Token an toàn rồi kết nối broker bằng TLS.
5. Test tenant/project isolation, concurrency, retry, credential và broker runtime.

### Ngoài phạm vi lượt đầu

- Lưu hoặc ràng buộc clientId với Device; strict binding tại CONNECT.
- Thay Mosquitto hoặc thêm auth adapter mới.
- Thay đổi telemetry ingestion hoặc chuyển xác thực publisher vào telemetry-service.
- Giới hạn số phiên MQTT đồng thời. Đây là policy riêng; provisioning không làm tăng năng lực kết nối của broker.

Access Token tiếp tục là credential đăng nhập MQTT duy nhất. Không thêm lựa chọn credential_type trong profile V1; mọi thiết bị provisioned sử dụng mode đã vận hành hiện tại.

## 4. Contract provisioning

### 4.1. Profile theo Project

Đề xuất V1 chỉ có một profile active cho mỗi Project; có thể mở rộng nhiều profile sau này nếu cần các dòng firmware hoặc quy trình enrollment khác nhau.

    project_provisioning_profiles
      id                       uuid PRIMARY KEY
      project_id               uuid NOT NULL UNIQUE FK -> projects.id
      enabled                  boolean NOT NULL DEFAULT false
      provision_key            varchar(64) NOT NULL UNIQUE
      provision_secret_hash    varchar(255) NOT NULL
      secret_rotated_at        timestamptz NULL
      created_at / updated_at

- provision_key là ID để tìm profile, không phải secret.
- provision_secret được sinh bởi Control Plane. Chỉ lưu hash để xác minh; UI chỉ hiển thị secret khi tạo hoặc rotate.
- Tắt profile chặn các bootstrap request mới nhưng không revoke Access Token đã cấp.
- Profile thuộc Project; Tenant xác định từ quan hệ Project, không nhập từ bootstrap request.

Profile management API/UI yêu cầu Sanctum, tenant context, route binding và policy tương ứng. V1 không cần lưu credential_type hoặc bản mã hóa của provision_secret.

### 4.2. Bootstrap endpoint

Đề xuất endpoint HTTPS:

    POST /api/device-provisioning/bootstrap

Request:

    {
      "provision_key": "<profile-key>",
      "provision_secret": "<profile-secret>",
      "device_name": "Pump room sensor 01",
      "device_code": "FACTORY-SERIAL-001",
      "request_id": "<stable-uuid-for-this-enrollment>",
      "firmware_version": "1.0.0"
    }

- device_code là mã ổn định của thiết bị do nhà sản xuất cấp; dùng ràng buộc unique hiện có của Device theo Project để phát hiện claim trùng. Nếu cần phân biệt mã hiển thị với hardware UID trong tương lai, bổ sung hardware_uid bằng migration riêng.
- hardware serial/device_code chỉ dùng nhận diện và chống tạo trùng, không phải bằng chứng xác thực. MAC có thể bị giả mạo.
- Không nhận tenant_id, project_id, access_token, token hash hoặc password trong request.
- Có thể bổ sung device_type hoặc firmware metadata sau khi chốt data contract; không đưa các giá trị không được xác minh thẳng vào quyền hoặc tenant scope.

Response thành công:

    {
      "status": "success",
      "credential_type": "access_token",
      "device": {
        "id": "<device-uuid>",
        "project_id": "<project-uuid>",
        "name": "Pump room sensor 01",
        "code": "FACTORY-SERIAL-001"
      },
      "credentials": {
        "access_token": "<generated-token>"
      },
      "mqtt": {
        "host": "<public-broker-host>",
        "port": 8883,
        "tls": true,
        "topics": {
          "telemetry": "liteboard/<project-id>/devices/<device-id>/telemetry",
          "heartbeat": "liteboard/<project-id>/devices/<device-id>/heartbeat",
          "commands": "liteboard/<project-id>/devices/<device-id>/commands",
          "commands_ack": "liteboard/<project-id>/devices/<device-id>/commands/ack"
        }
      }
    }

Firmware dùng access_token làm cả MQTT username và password theo contract LiteBoard hiện tại. Không đổi contract broker sang ThingsBoard wire format trong Phase 6; ThingsBoard gửi Access Token ở username và để password trống.

### 4.3. Profile secret và enrollment trust

V1 theo mô hình ThingsBoard: provision_key/provision_secret được phân phối cho firmware hoặc lô thiết bị thuộc Project. Secret dùng để cho phép enrollment, không dùng cho MQTT sau khi cấp Access Token.

Vì secret dùng chung có phạm vi ảnh hưởng toàn profile nếu bị trích xuất:

- Chỉ phân phối profile secret qua quy trình firmware/manufacturing được kiểm soát; không nhúng một secret chung cho mọi Project vào firmware công khai.
- Hỗ trợ disable/rotate profile secret, rate limit theo IP và profile, áp dụng quota Project, audit thao tác quản trị.
- Không ghi provision_secret, Access Token hoặc credential response vào log.
- Với production cần mức bảo đảm cao hơn, có thể thiết kế per-device one-time claim code trong một phase riêng. Không coi device_code/MAC là secret.

## 5. Luồng xử lý

1. Thiết bị lần đầu xác minh CA và hostname/SAN của HTTPS endpoint rồi gửi bootstrap request.
2. Control Plane tra profile bằng provision_key, xác minh provision_secret_hash, profile enabled, Project và Tenant active.
3. Control Plane khởi tạo đúng tenant context từ Project; không dùng project_id hoặc tenant_id do thiết bị gửi.
4. Kiểm tra quota và device_code. Request hợp lệ với Device chưa tồn tại tạo Device và credential Access Token; dùng DevicePersistenceService/DeviceCredentialService hiện có hoặc một domain operation chung để tránh tạo đường cấp token thứ hai.
5. Ghi Device, credential và provisioning receipt trong transaction. Unique constraint trên Project + device_code và profile + request_id là lớp bảo vệ cuối cho request đồng thời.
6. Trả Device ID, Access Token, broker endpoint và topic đã dựng từ dữ liệu server.
7. Firmware lưu credential vào secure storage/NVS, sau đó kết nối MQTT TLS 8883. Các lần reboot sau dùng credential đã lưu, không gửi lại profile secret.
8. Broker tiếp tục xác thực token và áp dụng per-Device ACL; telemetry-service vẫn là internal subscriber, không xác thực publisher thay broker.

Không mở MQTT plaintext 1883 hoặc WebSocket 9001. Bootstrap phải chạy qua HTTPS với kiểm tra CA/hostname; TLS của broker không bảo vệ request HTTP.

## 6. Idempotency, claim và quota

- Thiết bị giữ nguyên request_id cho đến khi nhận được kết quả bootstrap thành công; request retry phải giữ nguyên profile và device_code.
- Cùng profile + request_id + device_code trong cửa sổ retry trả lại cùng Device và cùng Access Token nếu credential vẫn active, chưa rotate.
- Cùng request_id nhưng payload/profile khác trả 409 IDEMPOTENCY_KEY_REUSED.
- device_code đã tồn tại trong Project nhưng thuộc một request khác trả 409 DEVICE_ALREADY_PROVISIONED; không trả lại credential hiện có. Re-claim chỉ qua thao tác admin có audit và rotate credential.
- Lưu provisioning receipt liên kết profile, request_id, device_code, Device ID, hash token lúc cấp và thời điểm hết hạn. Khi retry, chỉ giải mã encrypted_token hiện có nếu hash vẫn khớp snapshot và credential active; nếu token đã rotate/revoke, trả lỗi xác định, không tiết lộ token cũ.
- Quota phải áp dụng chung cho tạo Device qua UI/API và bootstrap. Repository migrations hiện chưa khai báo projects.max_devices_quota; trước rollout, kiểm tra active schema bằng lệnh db:table và phối hợp với Phase 8 để có một quota source of truth. Không duy trì provisioned_count thứ hai trong profile nếu nó có thể lệch với số Device thực tế.
- Quota check và claim/create phải an toàn khi nhiều request cùng lúc; dùng transaction/locking cùng các unique constraint trong database, không chỉ dựa vào validation trước khi insert.

## 7. Tenant isolation và quyền

- Device, credential và provisioning profile là tenant-owned data thông qua Project.
- Admin tạo/đổi profile trong Project mình có quyền quản lý; mọi API management dùng policy và tenant context hiện có.
- Public bootstrap không cần user session, nhưng chỉ có thể resolve đúng một Project qua profile key và secret hợp lệ.
- Query và transaction bootstrap phải chạy với organization context đúng theo integration multi-tenancy hiện có.
- Project hoặc Tenant inactive, profile disabled, secret sai/hết hạn, quota cạn hoặc credential revoke đều chặn enrollment/connection theo đúng boundary.
- Test profile của Project A không thể tạo Device trong Project/Tenant B; body không thể override Project ID.

## 8. Rollout và hạng mục thực hiện

1. **Chốt contract và prerequisites:** Access Token-only; profile theo Project; device_code là serial ổn định; xác định Project quota dùng chung với UI/API.
2. **Schema forward-only:** thêm provisioning profile, provisioning receipt và các unique constraint cần thiết. Không sửa migration đã chạy, không khai báo down().
3. **Admin profile management:** tạo, xem trạng thái/quota, disable và rotate secret; authorize theo Project.
4. **HTTPS bootstrap:** validation, secret verification, tenant context, quota, idempotency, atomic Device + Access Token creation và response contract.
5. **Clients:** simulator và ESP32 xác minh HTTPS certificate, bootstrap lần đầu, lưu Access Token, tái sử dụng sau reboot, kết nối MQTT TLS 8883 theo contract hiện có.
6. **Runtime rollout:** chạy broker bằng image/version được pin; xác minh bootstrap + MQTT connect/publish thực tế qua CA/SAN production-equivalent.

## 9. Tiêu chí nghiệm thu

- [ ] Device mới chưa tồn tại có thể bootstrap qua HTTPS bằng profile key/secret hợp lệ.
- [ ] Backend tạo đúng một Device trong Project của profile và cấp một Access Token theo DeviceCredentialService hiện có.
- [ ] Thiết bị nhận endpoint/topic cần thiết, lưu token bền vững và MQTT TLS 8883 kết nối bằng username=password=Access Token.
- [ ] Device đã tạo vẫn hoạt động qua luồng Access Token hiện tại; provisioning không đổi contract của client cũ.
- [ ] Sai secret, profile disabled, Project/Tenant inactive và quota cạn đều bị từ chối; không tạo Device dở dang.
- [ ] Request đồng thời cho cùng request_id/device_code không tạo trùng Device hoặc vượt quota.
- [ ] Retry cùng request trả cùng kết quả khi credential vẫn active; replay sau rotate/revoke không tiết lộ credential.
- [ ] Bootstrap không thể chọn Project/Tenant qua body; cross-Project và cross-Tenant đều bị chặn.
- [ ] Provision secret và Access Token không xuất hiện trong log; secret chỉ hiển thị khi tạo/rotate; rate limit hoạt động.
- [ ] MQTT anonymous, token sai, TLS CA/hostname sai và broker plaintext 1883/9001 đều thất bại.
- [ ] Runtime broker thật được kiểm thử bằng artifact đã pin; docker compose config hoặc unit tests đơn lẻ chưa đủ để đánh dấu Phase 6 hoàn tất.

## 10. Ngoài phạm vi và ghi chú hiện trạng

- Strict clientId binding chưa phải acceptance của Phase 6. Client ID hiện không thống nhất giữa Python simulator và ESP32, chưa lưu trong DB, và Access Token hiện không gắn với Client ID ở broker.
- TLS listener 8883 có cấu hình nhưng runtime chưa được xác nhận ở lần đối chiếu ngày 2026-10-01.
- mosquitto-go-auth hiện được cấu hình với tag latest chưa pin; pin artifact là điều kiện rollout broker, nhưng không cần đổi auth plugin chỉ để triển khai Access Token bootstrap.
- ACL nội bộ hiện cấp telemetry-service readwrite trên liteboard/# dù service chỉ cần subscribe/read. Nên theo dõi như hạng mục broker hardening riêng, không trộn với contract provisioning.
