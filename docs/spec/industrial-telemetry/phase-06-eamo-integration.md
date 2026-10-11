# IT-6 — Event contract với EAMO

> Trạng thái: kế hoạch; phụ thuộc IT-5 alert lifecycle, durable outbox và idempotent receiver contract.

## Mục tiêu

Gửi condition/alert lifecycle event từ LiteBoard sang một hoặc nhiều downstream consumer theo contract có version, retry, deduplication và khả năng đối soát.

## Event đề xuất

```json
{
  "event_id": "01J...",
  "event_type": "equipment.condition.detected",
  "event_version": 1,
  "occurred_at": "2026-10-07T10:00:30Z",
  "emitted_at": "2026-10-07T10:00:31Z",
  "tenant_id": "<tenant-uuid>",
  "project_id": "<project-uuid>",
  "alert_id": "<alert-uuid>",
  "rule": { "id": "<rule-uuid>", "version": 1 },
  "equipment": {
    "source_id": "<liteboard-asset-uuid>",
    "source_code": "MOTOR-01",
    "external_id": "<eamo-equipment-id>"
  },
  "device": { "id": "<device-uuid>", "code": "motor-01" },
  "condition": "high_temperature",
  "severity": "CRITICAL",
  "source_message": {
    "id": "01J...",
    "profile_version_id": "<profile-version-uuid>"
  },
  "evidence": { "temperature": 82.1, "unit": "celsius" }
}
```

`event_version` version hóa envelope/event type; nó không phải `schema_version` của telemetry. `occurred_at` lấy từ condition transition theo policy alert; `emitted_at` là lúc LiteBoard ghi event. Event clear/recovered dùng event type và payload được version hóa tương ứng. `external_id` chỉ được gửi khi có mapping EAMO cấu hình rõ; code như `MOTOR-01` không mặc nhiên duy nhất ngoài Project.

## Phạm vi

- Chốt authentication, cấu hình endpoint/mapping owner, timeout, retry/backoff, expiration, response semantics, secret rotation và giới hạn dữ liệu với EAMO. Không trộn credentials HTTP integration với MQTT Device Access Token.
- Persist event trong outbox cùng transaction với chuyển trạng thái alert. Gửi bất đồng bộ; một alert transition có một `event_id` ổn định qua mọi retry. Mỗi lần gửi có delivery attempt, timestamp và outcome riêng.
- Receiver duyệt contract version, lưu `event_id` trong inbox/dedup table cùng thay đổi nghiệp vụ trong một transaction. Duplicate trả response đã định nghĩa, không tạo hai nghiệp vụ.
- Retry tạo **at-least-once delivery**; contract hướng tới một tác động nghiệp vụ logic qua idempotent receiver, không tuyên bố exactly-once network delivery.
- Giữ Tenant/Project/Equipment/Device source, Profile Version, Rule revision, alert và telemetry provenance. Payload context không bao giờ cấp quyền truy cập trong LiteBoard.
- Xử lý trật tự transition cùng alert bằng sequence/version; consumer không áp dụng event cũ sau event mới.
- Cung cấp receiver example hoặc contract test không dùng production credential và runbook replay/reconciliation.
- EAMO sở hữu nghiệp vụ tiếp theo như work order, assignment, technician và maintenance history. LiteBoard vẫn hỗ trợ các consumer API/event khác.

## Nghiệm thu

- Alert detect và recovered đến receiver đã cấu hình; payload tra ngược được tới rule revision, alert, Device, Profile Version và source sample.
- Timeout trước/sau khi receiver commit, duplicate delivery, worker restart, receiver outage rồi phục hồi và event version không hỗ trợ đều có outcome xác định.
- Lặp delivery không lặp tác động nghiệp vụ. Event cũ không ghi đè transition mới.
- Kiểm thử mapping sai Project/Tenant và kiểm soát authorization của endpoint/configuration; dữ liệu không chảy sang integration của Tenant khác.
- HTTP 2xx semantics được chứng minh bằng receiver contract test; phân biệt accepted/deduplicated/rejected ở receiver nếu contract cần đối soát.
- Đo delivery latency, retry/error count, outbox age/depth, reconciliation và recovery time. Không coi HTTP 2xx đơn lẻ là bằng chứng nghiệp vụ đã hoàn tất.

## Ngoài phạm vi

Không chuyển work order, phân công technician hoặc maintenance history sang LiteBoard; không ràng buộc LiteBoard chỉ phục vụ EAMO.
