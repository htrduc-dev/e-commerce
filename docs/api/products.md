# GET /api/products

API public, không cần token. Chỉ trả sản phẩm khi đồng thời:

- product.status = published;
- seller tồn tại và seller.status = active.

Sản phẩm của seller pending/suspended hoặc không có seller hợp lệ bị ẩn.
Draft/archived bị ẩn bất kể seller status. Không lọc thêm theo tồn kho, verification
hoặc loại seller. Query parameters không thể bỏ qua các điều kiện visibility này.
Giữ thứ tự `created_at DESC, id DESC`. Pagination chỉ tính các sản phẩm visible,
bao gồm total/last_page/from/to; sản phẩm hidden không chiếm chỗ trên trang.

Query parameters:

| Tên | Mặc định | Hợp lệ |
| --- | --- | --- |
| `per_page` | 15 | Số nguyên từ 1 đến 100 |
| `page` | 1 | Số nguyên từ 1 đến 100000 |

Giá trị được truyền nhưng không hợp lệ, bao gồm rỗng, `per_page>100` hoặc
`page>100000`, trả
HTTP 422 JSON validation errors. Thành công trả HTTP 200:

```json
{
  "data": [
    {
      "id": 42,
      "name": "Sản phẩm mẫu",
      "slug": "san-pham-mau",
      "price": "9007199254740993"
    }
  ],
  "pagination": {
    "current_page": 1,
    "per_page": 15,
    "total": 1,
    "last_page": 1,
    "from": 1,
    "to": 1
  }
}
```

Mỗi phần tử chỉ có `id`, `name`, `slug`, `price`. `price` luôn là **JSON string**
biểu diễn số nguyên thập phân không âm, không có dấu phân cách hoặc phần thập phân
(ví dụ `"0"`, `"150000"`). Giá giữ nguyên giá trị trong database; không quy đổi.
Đơn vị tiền tệ chưa được xác nhận.

Client phải giữ chuỗi hoặc dùng kiểu số nguyên chính xác như JavaScript `BigInt`
khi cần tính toán; không chuyển qua float hay JavaScript `Number`. Giá có thể lớn
hơn `Number.MAX_SAFE_INTEGER`. Đổi `price` từ JSON number sang string là thay đổi
không tương thích đối với client đã phụ thuộc kiểu number; cần cập nhật schema,
parser và tính toán của client trước khi tích hợp.

Không có kết quả hoặc vượt trang cuối trả `data: []`, `from: null`, `to: null`.
Khi không có kết quả, `total=0`, `last_page=1`. Dữ liệu thay đổi giữa các request
có thể làm dịch trang vì API dùng offset pagination.

Composite index `(status, created_at, id)` được hoãn sang công việc hiệu năng
riêng; chưa thay đổi schema/index trong phạm vi API này. Offset lớn và truy vấn
count vẫn có thể tốn chi phí với bảng lớn.

Harness MySQL đã có và các price boundaries, gồm BIGINT UNSIGNED maximum
18446744073709551615, đã được xác minh bằng Gate C trước REV-003. Xem
[hướng dẫn harness](../testing/mysql-product-price.md). Bộ test visibility REV-003
phải được chạy lại sau approval riêng; kết quả Gate C cũ không thay thế việc xác minh
policy mới. MySQL tests chỉ dùng tester SELECT/INSERT, transaction/rollback và đầy đủ
safety guards; không tự chuẩn bị schema, migrate hoặc cleanup.

## GET /api/products/{id}

API public, cùng visibility policy với Listing. Chỉ published product có seller
active mới trả HTTP 200 JSON:

```json
{
  "data": {
    "id": 42,
    "name": "Sản phẩm mẫu",
    "slug": "san-pham-mau",
    "price": "150000"
  }
}
```

Chỉ có bốn public fields nêu trên; price luôn là JSON string với cùng quy tắc Listing.
Product thiếu, draft/archived, seller pending/suspended hoặc không tồn tại đều trả
HTTP 404 JSON, không trả product data. ID không phải số cũng trả 404.

Schema MySQL thật bắt buộc seller_id và có foreign key tới sellers.id với
ON DELETE CASCADE, nên không thể tạo orphan hợp lệ. Defensive orphan coverage chỉ
dùng SQLite :memory: fixture không khai báo FK; không tắt foreign key MySQL.
