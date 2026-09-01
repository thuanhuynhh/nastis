# Nastis

[English](README.md)

Plugin generic cho OJS 3.5, gửi metadata bài báo đã xuất bản và file PDF sang API
ingest của Vietnam Journals Online (VJOL) tại `https://vjol.vista.gov.vn`.

## Cài đặt

Chép thư mục này vào `plugins/generic/nastis` trong bản cài OJS, rồi bật
**Nastis** ở Cài đặt > Website > Plugin.

## Cấu hình

Mở phần cài đặt của plugin và điền:

- Base URL của máy chủ ingest
- Mã tạp chí do bộ cấp, đồng thời là tiền tố của mọi mã bài báo
- Client ID, gửi kèm header `x-client-id`
- API key, gửi kèm header `x-api-key`, lưu ở dạng mã hoá
- có tự động đồng bộ khi xuất bản và khi sửa hay không, có tải PDF lên hay không

Bấm **Kiểm tra kết nối** trước khi lưu. Nút này gọi `GET /health` rồi gọi tiếp một
API cần xác thực, nên phân biệt được ba trường hợp: không kết nối tới máy chủ,
thông tin xác thực bị từ chối (`AUTH_INVALID`), hoặc thông tin xác thực không
dùng được cho mã tạp chí bạn nhập (`JOURNAL_MISMATCH`).

## Plugin làm gì

Plugin thêm một trang **Nastis** vào menu biên tập, liệt kê các bài đã xuất bản
kèm trạng thái đồng bộ và cho đồng bộ từng bài. Trạng thái này cũng hiện trong
màn hình quy trình xử lý bài.

Các request gửi tới API ingest:

| Tình huống | Request |
| --- | --- |
| Gửi lần đầu | `POST /api/ingest/v1/articles` dạng `multipart/form-data`, metadata là một phần JSON kèm file |
| Sửa metadata sau đó | `PUT /api/ingest/v1/articles/{externalArticleId}` |
| Thêm hoặc thay file | `POST /api/ingest/v1/articles/{externalArticleId}/files` |
| Đọc lại trạng thái | `GET /api/ingest/v1/articles/{externalArticleId}/status` |

Plugin cũng mở hai endpoint trong OJS cho quản lý tạp chí, biên tập viên chuyên
mục và trợ lý: `PUT /api/v1/submissions/{submissionId}/nastis/sync` và
`GET /api/v1/submissions/{submissionId}/nastis/status`.

Nếu lệnh tạo mới trả về `409 PAYLOAD_CONFLICT`, plugin gửi lại payload bằng
`PUT` (mục 12.3 của đặc tả).

Đồng bộ tự động thất bại không chặn việc xuất bản hay chỉnh sửa. Plugin ghi lỗi
vào bài nộp (`nastisLastError`), vào nhật ký sự kiện và vào error log của PHP.

## Giới hạn

API chỉ nhận 10 request ghi mỗi phút, nên client chờ ít nhất 7 giây giữa hai lần
ghi và thử lại hai lần khi gặp `429 RATE_LIMITED`. Vì vậy đồng bộ nhiều bài sẽ chậm.

File phải là PDF và không quá 50 MB (đặc tả 3.5).

## Mã bài báo

Đặc tả 4.1 yêu cầu mã ngoài có dạng `{journalCode}-{submissionId}` và phải bắt
đầu bằng mã tạp chí gắn với thông tin xác thực, ví dụ:

```
vjol-121-tap-chi-suc-khoe-va-lao-hoa-155
```

Khi máy chủ đã nhận một mã thì mã đó không đổi nữa. Plugin dùng lại giá trị đã
lưu, chỉ sinh mã mới khi giá trị cũ không còn khớp mã tạp chí đang cấu hình, vì
máy chủ không thể đã chấp nhận một mã sai tiền tố.

## Yêu cầu

- OJS 3.5.0-x
- PHP xác minh được chuỗi chứng chỉ TLS của máy chủ ingest, với `curl.cainfo`
  hoặc `openssl.cafile` trỏ tới một CA bundle còn hạn. Thiếu nó thì mọi lần đồng
  bộ đều hỏng với `TRANSPORT_ERROR` và `cURL error 60`.
