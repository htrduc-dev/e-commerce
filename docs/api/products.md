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

## Public Product API rate limiting

Product Listing và Details dùng chung named limiter public-products:
**60 requests/phút/client IP** mặc định, cấu hình PRODUCT_API_RATE_LIMIT_PER_MINUTE.
Giá trị phải là số nguyên từ **1 đến 10000**, gồm hai biên (hoặc chuỗi số nguyên
hợp lệ trong cùng khoảng); ngoài khoảng hoặc sai định dạng gây lỗi, không chuyển
thành unlimited. Mặc định vẫn là 60. Không phân biệt authenticated/guest trên các API public.

Key có namespace riêng ecommerce-api:public-products:v1 và hash SHA-256 của
Request::ip() sau Laravel trusted-proxy processing. Không dùng product ID, query
parameters hoặc X-Forwarded-For đọc trực tiếp. IP không xác định dùng một fallback
bucket hữu hạn chung. Listing/Details/HEAD cùng dùng quota; đổi ID/query không cấp
quota mới. Request tới throttle, kể cả numeric-product 404 và validation 422, tiêu
thụ quota. Request không match route có thể không qua limiter.

Laravel dùng cửa sổ TTL bắt đầu từ request đầu, không phải lịch phút cố định; vẫn
có thể có burst và vượt nhẹ ngưỡng dưới concurrency do check/increment tách bước.
Client chung NAT có thể chia sẻ quota. Giới hạn này không thay thế edge DDoS protection.

Khi vượt quota, middleware trả HTTP 429 JSON kể cả khi thiếu Accept:

```json
{"message":"Too many requests."}
```

Giữ các headers Laravel cung cấp: X-RateLimit-Limit, X-RateLimit-Remaining=0,
Retry-After (giây), X-RateLimit-Reset (Unix timestamp). Response 429 có
Cache-Control chứa no-store. Client nên đợi ít nhất Retry-After rồi retry với backoff,
không retry vòng lặp liên tục. Response thành công có limit/remaining headers;
exception responses 404/422 không được bảo đảm có đủ các headers này.

Public fields, BIGINT price string, seller visibility, sorting, validation và
pagination khi request được phép giữ nguyên. Auth và health routes không dùng limiter này.

### Cache failure và production prerequisites

Dùng store Laravel resolve từ cache.limiter, hoặc cache.default nếu chưa cấu hình
riêng. Không tự bật Redis, không tạo cache tables hoặc thay MySQL tester grants.
Store failover/null bị từ chối; array chỉ được chấp nhận trong APP_ENV=testing.
Không có fallback code sang array hoặc unlimited khi cache lỗi.

Đã đọc vendor CacheServiceProvider/RateLimiter/ThrottleRequests/FailoverStore:
exceptions từ cache thông thường đi lên error handler (5xx), không bị đổi thành
quota exhaustion/429, và không chủ động cho controller chạy tiếp. Không thêm custom
outage status/body hoặc exception renderer trong scope này. Một số cache operations
có thể trả false thay vì ném exception; limiter chuẩn không xác minh mọi false result.
Do đó chưa bảo đảm fail-closed cho mọi kiểu backend failure. Chính sách outage chặt
hơn và kiểm thử store thực tế cần approval riêng, không được suy ra từ mock tests.

**Chưa production-ready** cho tới khi xác minh:
- Mọi API instance cùng môi trường dùng cùng persistent shared store và prefix;
  môi trường/ứng dụng khác phải tách namespace. Local file/array
  không chứng minh quota toàn deployment. Database cache hiện có trong config là
  một lựa chọn cần xác minh tables/quyền/capacity; Redis không được giả định sẵn có.
- Cache backend đủ quyền cho counter operations; không mở rộng quyền tester chỉ để
  phục vụ cache. Không chọn failover sang process-local state khi backend lỗi.
- Trusted proxy CIDRs/header contract và origin access controls đúng deployment.
  Không tự trust wildcard; proxy phải sanitize forwarded headers. Trust sai có thể
  cho spoof IP; không trust ingress đúng có thể gom mọi client thành một IP.
  Laravel có auto-trust behavior trên một số hosted environments, cần review thực tế.
- APP_DEBUG=false, monitoring 429/cache errors/latency và chính sách khi cache lỗi
  được duyệt. Nếu frontend cross-origin cần đọc headers, review CORS exposure riêng.
- Mức quota phù hợp client/NAT và nhu cầu thật; chưa có benchmark hoặc load test.

Implementation này không đổi proxy/cache infrastructure, database schema/indexes,
CI config hoặc MySQL harness. Tests mới dùng array store và stub controller trên
route/middleware thật, không query database; tests Product hiện có giữ coverage
controller/visibility/response thực tế. Shared-store và proxy deployment verification
là gate riêng. Không chạy migrations, MySQL integration hoặc Docker trong scope này.
