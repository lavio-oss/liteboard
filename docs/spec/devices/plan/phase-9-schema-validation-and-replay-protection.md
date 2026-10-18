# Phase 9: Telemetry Schema Validation & Chống Replay / Trùng lặp Gói tin

> Trạng thái: Future plan. Cập nhật 2026-10-18: kiểm tra payload theo schema có version và khử trùng lặp đã được triển khai theo thiết kế khác với tài liệu này (Profile Version trong PostgreSQL, bảng định danh message và ràng buộc unique, không dùng Redis hay `telemetry_schemas`), xem [telemetry flow hiện hành](../../industrial-telemetry/current/telemetry-flow.md#5-contract-payload). Tài liệu này cần được rà lại hoặc rút gọn.  
> Thuộc kế hoạch: [future_device_security_and_ingestion_plan.md](./future_device_security_and_ingestion_plan.md)  
> Tầng kiến trúc: **Laravel Control Plane (`telemetry_schemas`) + Python Data Plane (`telemetry-service`) + Redis + PostgreSQL**  
> Phụ thuộc: [luồng MQTT Access Token hiện hành](../current/devices_access_token.md#mqtt-authentication), [Phase 8](./phase-8-quotas-and-rate-limiting.md)

---

## 1. Mục tiêu của Phase 9

Đảm bảo mọi bản tin telemetry đi vào cơ sở dữ liệu thời gian thực (Time-Series Storage) đều **đúng cấu trúc, đúng kiểu dữ liệu, nằm trong ngưỡng hợp lệ và duy nhất về mặt logic**:
1. **Replay & Deduplication Protection**: Chặn các bản tin bị phát lại (replay attack), lệch đồng hồ quá mức (clock skew), hoặc bị gửi trùng lặp do cơ chế retry của MQTT QoS 1.
2. **Telemetry Schema Validation**: Xác thực chặt chẽ phần `values` của bản tin dựa trên cấu hình `TelemetrySchema` có phiên bản (`schema_version`) gắn với từng Device/Profile.

---

## 2. Thiết kế Kỹ thuật Chi tiết

### 2.1. Chống Phát lại & Trùng lặp (`Replay & Deduplication`)

#### Bước 1: Kiểm tra Envelope & Cửa sổ Thời gian (`Time-Window / Replay Check`)
Mọi bản tin gửi vào `liteboard/{project_id}/devices/{device_id}/telemetry` bắt buộc có cấu trúc Envelope:
```json
{
  "message_id": "01JXYZ890ABCDEF",
  "schema_version": 1,
  "occurred_at": "2026-09-27T10:00:00Z",
  "values": {
    "temperature": 64.2,
    "vibration": 1.72,
    "rpm": 1450
  }
}
```
Khi `telemetry-service` nhận bản tin tại thời điểm `received_at` (giờ chuẩn UTC của server):
- **Chặn tương lai xa (`MAX_FUTURE_SKEW = 300s` — 5 phút)**:
  - Nếu `occurred_at > received_at + 5 phút` → Từ chối với mã `TIMESTAMP_IN_FUTURE`.
- **Chặn gói tin quá cũ (`MAX_PAST_AGE = 86400s` — 24 giờ, cấu hình được theo Profile)**:
  - Nếu `occurred_at < received_at - 24 giờ` → Từ chối với mã `TIMESTAMP_TOO_OLD` (chống kẻ tấn công thu thập gói tin cũ và phát lại sau nhiều ngày).

#### Bước 2: Khử trùng lặp 2 tầng (`Deduplication / Idempotency`)
Do MQTT `QoS 1` đảm bảo *at-least-once delivery*, khi mạng yếu thiết bị sẽ gửi lại gói tin có cùng `message_id`:
1. **Tầng 1 — Kiểm tra nhanh tại bộ nhớ Redis (`O(1)`)**:
   ```text
   SET dedup:{device_id}:{message_id} 1 NX EX 86400
   ```
   - Nếu Redis trả về `nil` (khóa đã tồn tại trong vòng 24 giờ qua) → Đánh dấu `DUPLICATE_MESSAGE`, bỏ qua ngay lập tức mà không ghi trùng xuống DB.
2. **Tầng 2 — Ràng buộc toàn vẹn tại PostgreSQL**:
   - Bảng `telemetry_messages` (hoặc `device_telemetry`) có ràng buộc `UNIQUE (device_id, message_id)`.
   - Câu lệnh ghi dữ liệu sử dụng `INSERT INTO ... ON CONFLICT (device_id, message_id) DO NOTHING`, đảm bảo ngay cả khi Redis vừa khởi động lại hoặc nhiều worker chạy song song cũng **không bao giờ sinh ra 2 bản ghi trùng nhau**.

---

### 2.2. Telemetry Schema Validation (Xác thực Cấu trúc & Miền Giá trị)

#### Thiết kế Bảng `telemetry_schemas` trên Control Plane
```text
telemetry_schemas
  id              uuid PRIMARY KEY
  project_id      uuid NOT NULL FK -> projects.id
  name            varchar(255) NOT NULL
  code            varchar(64) NOT NULL
  version         integer NOT NULL DEFAULT 1
  strict_mode     boolean NOT NULL DEFAULT true  -- true: cấm gửi thêm field lạ ngoài schema
  fields_definition jsonb NOT NULL
  status          varchar(32) NOT NULL DEFAULT 'active'
  created_at      timestamptz
  updated_at      timestamptz

  UNIQUE (project_id, code, version)
```

Ví dụ `fields_definition` (`jsonb`):
```json
{
  "temperature": {
    "type": "float",
    "required": true,
    "min": -40.0,
    "max": 250.0,
    "unit": "celsius"
  },
  "rpm": {
    "type": "integer",
    "required": true,
    "min": 0,
    "max": 10000,
    "unit": "rpm"
  },
  "overheat_alarm": {
    "type": "boolean",
    "required": false
  }
}
```

#### Quy trình Xác thực tại `telemetry-service`
1. `telemetry-service` lấy `fields_definition` của `(device_id, schema_version)` từ Redis Cache (nếu cache miss thì đọc từ PostgreSQL và lưu cache).
2. Kiểm tra từng trường trong `payload.values`:
   - **Thiếu trường bắt buộc (`required: true`)** → Từ chối với mã `MISSING_REQUIRED_FIELD`.
   - **Có trường lạ khi `strict_mode = true`** → Từ chối với mã `UNKNOWN_FIELD`.
   - **Sai kiểu dữ liệu** (ví dụ gửi `"64.2"` kiểu chuỗi cho trường `float`, hoặc gửi `true` cho trường `integer` — lưu ý trong Python `isinstance(True, int)` là `True` nên phải kiểm tra `type(val) is int`) → Từ chối với mã `INVALID_FIELD_TYPE`.
   - **Vượt ngưỡng `min` / `max`** (hoặc giá trị `NaN` / `Infinity`) → Từ chối với mã `VALUE_OUT_OF_RANGE`.
3. **Lưu vết rõ ràng (Traceable Outcome)**:
   - Bản tin hợp lệ (`ACCEPTED`): Lưu vào bảng dữ liệu chuỗi thời gian và phát sự kiện sang Rule Engine / WebSocket.
   - Bản tin không hợp lệ (`REJECTED`): Không lưu vào bảng chuỗi thời gian chính, mà ghi vào bảng giới hạn `telemetry_rejections` (hoặc Message Inspector stream) kèm mã lỗi (`reason_code`) và tên trường lỗi (`field_name`) để kỹ sư dễ dàng chẩn đoán trên Web UI.

---

## 3. Checklist Nghiệm thu Phase 9

- [ ] Gửi 2 bản tin có cùng `(device_id, message_id)` chỉ tạo ra đúng **1 bản ghi** trong PostgreSQL, bản tin thứ 2 được ghi nhận vào bộ đếm `duplicate`.
- [ ] Bản tin có `occurred_at` lệch quá 5 phút về tương lai hoặc cũ hơn 24 giờ bị từ chối với mã lỗi `TIMESTAMP_IN_FUTURE` / `TIMESTAMP_TOO_OLD`.
- [ ] Bản tin thiếu trường `required`, sai kiểu dữ liệu hoặc vượt ngưỡng `[min, max]` bị chặn lại và hiển thị rõ lý do từ chối trong bảng kiểm tra lỗi.
