# MySQL unsigned BIGINT price tests

## Trạng thái

`api/tests/MySql/ProductPriceBigIntTest.php` là test scaffold cho hai literal
string `9223372036854775808` và `18446744073709551615`. **Harness MySQL chưa được
provision, chưa kết nối và chưa chạy kiểm thử MySQL.** Không xem scaffold này là
bằng chứng end-to-end MySQL đã đạt.

Test đặt ngoài `tests/Feature` và `tests/Unit`, gắn PHPUnit group `mysql`.
`api/phpunit.xml` chỉ khám phá hai thư mục Unit/Feature, nên PHPUnit mặc định và
`--testsuite Feature` không chạy hoặc bootstrap class MySQL. Không sửa cấu hình
SQLite hiện tại.

`ProductIndexTest` kiểm tra endpoint với `9007199254740993` và signed maximum
`9223372036854775807` trên SQLite `:memory:`. SQLite INTEGER không lưu được toàn
bộ MySQL BIGINT UNSIGNED; không dùng REAL để giả lập hai unsigned boundaries.

## Gate hiện tại

`setUp()` kiểm tra opt-in **trước `parent::setUp()`, Laravel bootstrap**, connections
hoặc migrations. Không có `ECOMMERCE_MYSQL_PRICE_TEST_OPT_IN=1`: skip rõ lý do.
Có opt-in nhưng hook `createApplicationFromVerifiedEphemeralHarness()` chưa được
triển khai: vẫn skip trước parent setup. Hook hiện trả `null`, không bootstrap,
không đọc config database hoặc mở connection. Không có `RefreshDatabase`, không
tự chạy migrations. Scaffold chưa runnable với MySQL.

Biến opt-in chỉ diễn đạt ý định, không chứng minh isolation. Không được bỏ blocker
chỉ vì `APP_ENV=testing`, tên database có chữ test, hostname loopback, hoặc có
credentials riêng. Shared/dev/production đều không được dùng.

## Công việc cần phê duyệt trước khi kích hoạt

1. Provision MySQL 8.x local ephemeral instance mới hoàn toàn cho mỗi lần chạy,
   dùng storage tạm thời, không mount data/credentials/config của ứng dụng khác.
   Runner không có network route hoặc credentials đến shared/dev/production.
2. Runner kiểm tra instance identity và cấu hình thật từ runtime quản lý instance:
   ID instance vừa tạo, image/version, storage tạm, không shared volume, network
   namespace cô lập và endpoint duy nhất của instance đó. Chỉ env/tên DB không đủ.
3. Runner truyền cấu hình trực tiếp từ instance đã xác minh; không đọc `.env`
   thật, không dùng cached config, DB_URL, read/write hosts hoặc socket fallback.
   Chỉ cấp tài khoản giới hạn cho database mới trên instance ephemeral.
4. Triển khai hook bằng adapter harness đã review: xác minh isolation trước bootstrap;
   kiểm tra effective Laravel config trước mở connection; từ chối mọi mismatch
   hoặc fallback. Hook chỉ trả Laravel Application đã bootstrap sau các kiểm tra;
   thiếu bằng chứng isolation thì trả `null` để skip. Giữ guard SQLite của Feature
   suite nguyên vẹn.
5. Sau phê duyệt riêng, runner dùng migrations hiện có để tạo schema trong instance
   ephemeral, không sửa schema/migrations của dự án. Mỗi dataset cần database sạch
   hoặc transaction rollback được harness quản lý để assertion `data` không lẫn
   fixture lần trước. Cleanup chỉ được hủy instance ephemeral đã xác minh identity.
6. Chạy preflight rồi mới gọi PHPUnit bằng file path và group tường minh. Không
   chạy Feature suite SQLite với overrides MySQL, không dùng `composer setup`.

Lệnh đích dưới đây chỉ là tài liệu cho runner tương lai; **không chạy lúc này**:

```bash
./vendor/bin/phpunit tests/MySql/ProductPriceBigIntTest.php --group mysql
```

Runner tương lai phải cung cấp opt-in, config cách ly và lifecycle nêu trên;
không có lệnh kết nối MySQL có thể chạy an toàn trong repository hiện tại.
