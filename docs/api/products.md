# GET /api/products

API public, không cần token. Chỉ trả sản phẩm có `status=published`, sắp xếp
`created_at DESC, id DESC`. Không lọc theo trạng thái seller hoặc tồn kho.

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

Harness MySQL chưa được triển khai; các giá trị `price > PHP_INT_MAX` chưa được
xác minh runtime MySQL. Xem [hướng dẫn harness](../testing/mysql-product-price.md)
về scaffold kiểm thử unsigned BIGINT và điều kiện cách ly cần có trước khi chạy.
