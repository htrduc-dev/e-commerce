# MySQL Product API integration tests

## Trạng thái

Đã xác minh runtime trên môi trường test chuyên dụng MySQL 8.4.13, ngày 2026-11-18:

- Isolated unit suite: **204 tests, 415 assertions, 0 failures/skips**.
- P3-C read-only preflight: PASS cho tester và migrator.
- P3-D: repository initialization thành công.
- P3-E Recovery: 13 application migrations, 0 registered package migrations;
  preview 46 statements và lưu manifest SHA-256 sau khi xác minh DB không đổi.
- P3-F: 13/13 migrations hoàn tất ở batch 1; 18 application tables cộng repository,
  tất cả InnoDB; 19 primary keys, 8 explicit indexes, 9 unique constraints, 11 FKs;
  products.price là BIGINT UNSIGNED NOT NULL.
- Gate C: **22 tests, 107 assertions, 0 failures/skips**, PHPUnit exit code 0,
  dùng tester với SELECT/INSERT. Structural schema, 13 migration records và server
  identity không đổi sau test; mọi application table vẫn rỗng, không còn fixtures.

Kết quả này áp dụng cho baseline đã duyệt, không tự cấp quyền chạy lại.
Giữ nguyên cấu hình PHPUnit SQLite, Product API và business migrations.
SQLite regression không được chạy lại trong Gate C/final cleanup.

## Gates và operations độc lập

- **A:** triển khai/review sáu file harness, test và tài liệu; chỉ static checks.
- **B1:** developer duyệt environment/container inspection, kết nối read-only và
  kiểm tra actual database identity, grants của profile, source host và schema state.
  Không đổi session settings, schema, data hoặc quyền.
- **Repository initialization:** approval ghi riêng, opt-in `initialize`; chỉ tạo
  repository trên DB rỗng. Không tự chạy từ preflight hoặc tests.
- **B2 preview:** developer duyệt migration profile riêng; review source migration,
  kiểm schema dumps rồi preview pending migrations. Không tự apply sau preview.
- **B2 apply:** developer review output preview và duyệt apply riêng. DDL không
  rollback an toàn bằng transaction. Không reset/fresh/truncate/drop tự động.
- **C:** developer duyệt suite và fixture DML/rollback; preflight lại trước chạy.
  Không tự chuẩn bị schema khi test bị BLOCKED. SQLite regression cần approval riêng.

Opt-in chỉ là interlock, không phải bằng chứng developer approval (REV-004).
Không operation nào của preflight tự chạy PHPUnit hoặc migration. Không đổi Docker
lifecycle, không xóa named volume và không commit/push.

## Runtime prerequisites và identity

PHP CLI chạy trên macOS, PHP 8.3+, có `pdo_mysql`, DOM, mbstring, XML, XMLWriter,
POSIX (`posix_geteuid`), `proc_open`, `flock`; Composer dependencies hiện có.
Docker CLI/daemon local phải hoạt động. PHPUnit chạy trên macOS, dùng TCP
`127.0.0.1:3307`, không socket hoặc Docker service hostname.

Container `ecommerce-mysql-test`, Compose project `ecommerce-integration-test`,
service `mysql-test`, network `ecommerce-integration-test_default`; MySQL chính xác
`8.4.13`. Chỉ bind `127.0.0.1:3307 -> 3306`; named volume
`ecommerce-integration-test_mysql_test_data` tại `/var/lib/mysql` được tái sử dụng.

Các prerequisite môi trường, cấp qua kênh local an toàn:

```text
APP_ENV=testing
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3307
DB_DATABASE=ecommerce_test
DB_URL=
DB_SOCKET=
DB_USERNAME=
DB_PASSWORD=
ECOMMERCE_MYSQL_CONTAINER_ID=be3ca8665d0b8f1bc0dee25a4bd017b7e6c4c832e07998d98120305e1965a0d5
ECOMMERCE_MYSQL_IMAGE_ID=sha256:a81d043930e51745522bd5c430961cf8286f0a23f0a9a2a6e0108f7ea71b0902
ECOMMERCE_MYSQL_SERVER_UUID=46141f1d-e257-11f1-ab68-66627604040d
ECOMMERCE_DOCKER_BINARY=<reviewed canonical absolute executable path>
ECOMMERCE_DOCKER_SHA256=<reviewed executable SHA-256>
```

Docker symlink path phải được resolve trước; không tìm executable bằng PATH.
Harness kiểm canonical path, executable bit, hash và từ chối group/world-writable
binary. Identity/hash phải đến từ provisioning hoặc thao tác thu thập đã duyệt,
không tự lấy giá trị từ server bất kỳ rồi chấp nhận. Docker metadata inspection
không đọc Config.Env. Container recreation hoặc binary update cần review identity lại.
Không kết nối DB trong Gate A để thu thập UUID.

## Docker Compose secrets và cấu hình local

`docker/compose.mysql-test.yml` không chứa password literal. Compose nhận hai
**đường dẫn không phải secret** từ environment rồi mount secret files read-only:

- `ECOMMERCE_MYSQL_COMPOSE_ROOT_SECRET_FILE` → `/run/secrets/mysql_root_password`.
- `ECOMMERCE_MYSQL_COMPOSE_TESTER_SECRET_FILE` → `/run/secrets/mysql_tester_password`.

Official MySQL image đọc `MYSQL_ROOT_PASSWORD_FILE` và `MYSQL_PASSWORD_FILE`.
Healthcheck đọc tester secret file, không đưa password vào command arguments.
Không đặt password trực tiếp trong Compose environment.

Vị trí đề xuất đã được ignore bởi `api/storage/framework/testing/.gitignore`.
Chạy các lệnh sau từ repository root, sau khi operator đã cung cấp secret files:

```bash
export ECOMMERCE_MYSQL_COMPOSE_ROOT_SECRET_FILE="$PWD/api/storage/framework/testing/compose-secrets/mysql-root"
export ECOMMERCE_MYSQL_COMPOSE_TESTER_SECRET_FILE="$PWD/api/storage/framework/testing/compose-secrets/mysql-tester"
docker compose -f docker/compose.mysql-test.yml config --quiet
```

Missing/empty path variables làm Compose interpolation thất bại. Trước lifecycle
được duyệt, operator phải kiểm từng secret path là absolute, regular file, không
symlink, non-empty, owner đúng, mode 0600 và parent directory mode 0700. Xác minh
ignored bằng `git check-ignore --quiet -- "$ECOMMERCE_MYSQL_COMPOSE_ROOT_SECRET_FILE"`
và tương tự cho tester. Không dùng đường dẫn ngoài vùng ignored chưa review.
`config --quiet` không in resolved values nhưng chỉ validate cấu hình, không chứng
minh nội dung file/password đúng. File thiếu/unreadable không thể cung cấp secret
cho container. Không chạy lifecycle khi prerequisite thiếu.

Cleanup này không tạo secret files. Developer/DBA cung cấp **password hiện hữu**
từ approved password manager/Keychain vào file descriptor với umask 077; không echo,
xtrace, password arguments hoặc shell-history literals. Không yêu cầu coding agent
nhận root secret hoặc giả định root secret đã có trong Keychain. PHP tiếp tục lấy
tester/migrator credentials từ Keychain theo profile; Compose không cần migrator
password. Không lưu credentials trong Git, docs, manifest hoặc baseline env file.

Local Compose secrets là plaintext files, không phải encrypted secret store; bảo
vệ filesystem và backups. YAML mới không sửa container đang chạy. Container hiện
hữu có thể còn legacy password environment từ lúc tạo; không inspect/in secret
environment. Xử lý residual environment là task lifecycle riêng cần approval.

Image `mysql:8.4`, container/project/service, loopback port, named volume và default
network giữ nguyên. `up` có thể recreate container do config thay đổi: không chạy
`up/down/restart` trong cleanup. Secret _FILE không rotate account password trên
volume đã initialized. Không dùng Compose để provision lại schema/accounts:
volume mới có thể nhận quyền mặc định rộng cho MYSQL_USER, không đáp ứng harness;
phải qua quy trình provisioning/review riêng.

## Local-only artifacts

Giữ ignored/untracked: `api/.env`, non-secret baseline
`api/storage/framework/testing/mysql-preflight.local.env`, preview manifest,
`mysql-3307.lock` và mọi secret files trong `storage/framework/testing/compose-secrets/`.
Ba artifacts hiện hữu có mode 0600; credentials thực ở macOS Keychain.
Không dùng `git add -f` hoặc `git add .` để gom local artifacts. Docker path/hash,
UUID/container/image/source host là public baseline có chủ đích, không phải secrets;
macOS/version/source-host portability vẫn giới hạn như phần deferred findings.

## Credential profiles và quyền

Không dùng `DB_USERNAME`/`DB_PASSWORD` chung. Profile được inject sau LoadConfiguration
và trước provider boot; `.env` thật không được đọc. Không đưa password vào XML,
shell history hoặc logs. Mỗi process chỉ nhận một profile password:

- **Test/B1/C:** `ECOMMERCE_MYSQL_TEST_PASSWORD`; exact identity
  `ecommerce_tester@%`; đúng SELECT, INSERT trên scope literal `ecommerce\_test`.
- **Migration/P3-C/init/B2:** `ECOMMERCE_MYSQL_MIGRATION_PASSWORD`; exact identity
  `ecommerce_migrator@172.19.0.1`; đúng SELECT, INSERT, CREATE, ALTER, REFERENCES
  trên cùng scope. Không expose test password trong migration process hoặc ngược lại.

P2 identities/grants do developer xác nhận. Không giả định source IP 172.19.0.1
ổn định: host/grants/roles thay đổi phải BLOCKED và review riêng, không tự mở rộng
sang %. Không đổi password hoặc provisioning account từ harness. Không dùng root.

### Pure grant parser và exact escaped scope

`MySqlGrantPolicy::validate()` chỉ xử lý dữ liệu đầu vào, không đọc environment,
bootstrap Laravel, inspect Docker hoặc mở PDO. Baseline bắt buộc partial_revokes=0;
chỉ nhận raw database identifier có **một backslash** trước underscore:

```sql
GRANT SELECT, INSERT ON `ecommerce\_test`.* TO `ecommerce_tester`@`%`
GRANT SELECT, INSERT, CREATE, REFERENCES, ALTER ON `ecommerce\_test`.* TO `ecommerce_migrator`@`172.19.0.1`
```

Không strip backslashes hoặc decode identifier tổng quát. Scope `ecommerce_test`
không escape bị từ chối vì underscore có thể là wildcard khi partial_revokes=0.
Wildcard/prefix/suffix/khác database/table/column/global privileges đều bị từ chối.
Các grant phải đúng principal của authenticated account/profile. Thứ tự privileges
và rows không ảnh hưởng; split privilege rows hợp lệ được hợp nhất, nhưng duplicate
privileges/USAGE bị từ chối. Global USAGE đúng principal được chấp nhận tối đa một
lần và có thể không xuất hiện. Không chấp nhận roles, GRANT/ADMIN OPTION, ALL
PRIVILEGES, quyền thiếu/thừa, SQL suffix/comment/newline hoặc grant không phải string.
Diagnostic không echo raw grants/caller values. Đây là parser hẹp cho SHOW GRANTS
format đã duyệt, không phải SQL/grant parser tổng quát.

Identity runtime kiểm exact account trước SHOW GRANTS, CURRENT_ROLE()=NONE,
mandatory_roles rỗng, activate_all_roles_on_login=0 và partial_revokes=0. SHOW GRANTS
còn phát hiện assigned roles kể cả khi không active. Mỗi PDO được verify riêng,
không cache DB identity. B1 vẫn chỉ dùng SELECT/SHOW, không thay session.

`tests/Unit/MySqlGrantParserTest.php` kế thừa trực tiếp PHPUnit Framework TestCase,
không Laravel TestCase/RefreshDatabase. Chỉ include declarations/autoloader và gọi
pure parser; không environment guard, Docker, MySQL, SQLite hoặc fixture I/O.
Positive datasets: cả hai profiles, quyền/rows reorder, optional USAGE, split rows.
Negative datasets: escaped-scope sai/double-escape/unescaped/wildcards, wrong
principal/host/profile, partial_revokes mismatch, thiếu từng quyền migrator, quyền
thừa tester/migrator, duplicate rows/privileges, global/role/proxy/column grants,
GRANT OPTION, malformed SQL và diagnostics không lộ synthetic private marker.
REV-020 parser-only run trước đó đạt 94 tests/181 assertions. P3-C bổ sung pure request/identity/schema tests; isolated suite mới đạt 204 tests/415 assertions, tách biệt với runtime MySQL evidence.

## Harness và suite guard

Guard từ chối cached config, DB URLs/socket/read-write hosts, remote Docker
overrides, parallel tokens và unsafe SQL options; loại connection thay thế trước
provider boot. Từ chối manager đã resolved trước khi cài resolver. Resolver trả
`GuardedMySqlConnection`; kiểm instance/config trước `getPdo()`. Lazy PDO kiểm lại
container/config trước kết nối và actual DATABASE/VERSION/server UUID/CURRENT_USER/
SHOW GRANTS trước trả PDO. B1 còn gọi identity tường minh trên actual PDO, không chỉ
trông cậy callback. Identity/grants dùng SELECT/SHOW; không SET hoặc DML.

`mysql-suite-bootstrap.php` đọc effective PHPUnit Configuration từ Registry trước
khi discovery bắt đầu; từ chối noExtensions, thiếu MySQL config hoặc bootstrap sai.
Inventory dùng file_get_contents + token_get_all(TOKEN_PARSE), không require/include/
eval hoặc reflect/autoload candidate để tìm class. Nó quét mọi PHP trong tests/MySql
(trừ base harness đã review), cộng direct-file arguments; directory argument ngoài
suite, symlink, nhiều class/file, interface/trait/enum declaration và inheritance
không trực tiếp từ MySqlTestCase đều bị từ chối. Chỉ hỗ trợ namespace không bracket,
imports đơn không alias/group và class header chuẩn; đây là policy fail-closed hẹp,
không phải parser PHP tổng quát. Các class/file hợp lệ được lưu hash trong process.
Filter không bỏ qua inventory của suite. Validation không thực thi candidate, nhưng
candidate đã vượt kiểm tra hình dạng vẫn phải được reviewer tin cậy trước discovery.

`mysql-suite-bootstrap.php` nạp PHPUnit 12 extension qua XML. Extension đăng ký
TestSuite Loaded và TestRunner ExecutionStarted subscribers, kiểm các TestMethod
đã discovery và tập execution sau filter. Mọi test phải kế thừa MySqlTestCase;
loại test không hỗ trợ hoặc trait cấm khiến exit(1), không skip. Collection size phải
khớp suite count để phát hiện custom Test bị event builder bỏ qua; class/file/hash
phải khớp inventory trước discovery. Class hoặc trait liên quan được đưa vào BLOCKED
message; không in exception tùy ý hoặc credentials. PHPUnit 12 nuốt
exception từ subscriber thành warning, nên guard chủ động exit để dừng execution.
API được đối chiếu vendor: Runner/Extension/{Extension,Facade}, Event/Events/
TestSuite/Loaded, TestRunner/ExecutionStarted, Event/Value/TestSuite/TestSuiteBuilder,
TextUI/Configuration/Registry và Event/Dispatcher/DirectDispatcher. Registry/event
value API phụ thuộc PHPUnit version đang cài, phải review lại khi upgrade.

MySqlTestCase vẫn kiểm guard đã chạy, opt-in, traits và isolation trước
`parent::setUp()`. Từ chối traits đệ quy/kế thừa: RefreshDatabase,
DatabaseMigrations, DatabaseTruncation, DatabaseTransactions. Gọi file trực tiếp
hoặc --filter vẫn dùng config MySQL dưới đây. Nếu bỏ XML/bootstrap/extensions,
MySqlTestCase phải fail trước Laravel setup, không chuyển sang bootstrap thường.

Khóa local dùng file cố định `api/storage/framework/testing/mysql-3307.lock`, mode
0600, regular file đúng owner/inode, không symlink/hardlink; giữ flock tới hết process.
Không unlink lock sau chạy. Mọi operation phải dùng cùng checkout và user. Không
chạy parallel. Không chỉnh lock khi process khác đang chạy.

## Docker cache, subprocess environment và Laravel session

REV-009: metadata container đã verify chỉ cache trong memory của process và khóa
bằng expected IDs/executable/hash/HOME/config path. Fresh check xóa cache cũ trước
inspect và lưu lại chỉ khi toàn bộ kiểm tra đạt. Environment calls lặp lại có thể
dùng cache; ngay trước **mỗi new PDO** bắt buộc inspect container/context mới, không
chấp nhận cache thay thế. Hash Docker executable được cache theo canonical path,
expected hash, device/inode/size/mtime/ctime/mode; fingerprint thay đổi phải hash lại.
Không ghi cache ra disk. Không cache actual DB identity/grants: mỗi PDO mới luôn
SELECT/SHOW để verify riêng. Schema guard có thể verify lại cùng PDO bằng query đọc.

REV-014: proc_open nhận environment array tường minh, chỉ HOME, DOCKER_CONFIG,
LANG=C, LC_ALL=C. Không truyền PATH, DB credentials, Laravel env hoặc opt-in flags.
DOCKER_CONFIG chỉ được trỏ tới HOME/.docker; absolute Docker executable đã review
được dùng trực tiếp. HOME và Docker context/config vẫn là trusted local inputs;
không coi config của user là sandbox. Khi Docker setup cần biến khác, BLOCKED và
review allowlist riêng, không fallback sang inherit toàn bộ environment.

REV-010: api/config/database.php hiện có strict=true, charset=utf8mb4,
collation=utf8mb4_unicode_ci; URL/socket/options/modes/timezone/isolation override
không an toàn vẫn bị từ chối. GuardedMySqlSession kế thừa vendor MySqlConnector,
chỉ gọi protected configureConnection trên **PDO đã guard**, không gọi connect().
Reflection xác minh API configureConnection/getSqlMode trước bootstrap; mismatch
=> BLOCKED, không tự viết workaround SQL. Vendor MySqlConnector áp dụng SET NAMES
và strict SQL mode từ effective config; expected SQL mode cũng lấy từ vendor.

B1 gọi application mặc định với configureSession=false: SELECT/SHOW only, không
SET session. B2/C bật configureSession sau identity/grants verification trên PDO;
SELECT kiểm SESSION sql_mode, character_set_client/connection/results và
collation_connection. MySqlTestCase kiểm lại session trước fixture. B1 vì thế xác
minh DB read-only, còn application-equivalent session chỉ được cấu hình ở B2/C.
Không cần grant bổ sung hoặc thay PDO để cấu hình session. Runtime session guard đã đạt trong P3-F và Gate C.

### Supported invocation và bypass limits (REV-008)

| Invocation | Policy |
|---|---|
| -c phpunit.mysql.xml, full suite | Token inventory trước discovery; check collection trước và sau filter. |
| Cùng config + direct PHP file | Inventory cả suite lẫn file CLI; reject unsupported class trước include candidate. |
| Cùng config + --filter | Không được filter để né inventory; execution set được kiểm lại. |
| --no-extensions, bootstrap chuẩn | Bootstrap từ chối trước discovery. |
| Custom --bootstrap | Bootstrap chuẩn có thể không chạy; MySqlTestCase còn fail thiếu execution guard. Arbitrary non-harness test không được bảo đảm. |
| --no-configuration | Harness test fail thiếu mandatory guard; arbitrary PHP ngoài harness không được bảo vệ. |

Không đổi SQLite phpunit.xml. Cấu hình thông thường được bảo vệ ở đây là MySQL XML.
Kết hợp CLI overrides cố tình bỏ cả config/bootstrap/extensions có thể bỏ toàn bộ
PHP guard. Token inspection không phân tích tác dụng của code: top-level PHP,
require khác, dynamic code, suite factory, autoload và data providers vẫn phải được
review. Data providers có thể chạy trước Loaded/ExecutionStarted subscribers.
Không chạy candidate không tin cậy chỉ để kiểm tra nó; dùng OS/network isolation
nếu cần security boundary. Không tuyên bố complete protection.

## P3-C — Read-only preflight cho cả hai profiles

Operation mới: `--read-only --profile=test` hoặc `--read-only --profile=migration`.
Chỉ chấp nhận đúng một explicit profile; unsupported/extra arguments => BLOCKED.
Profile được chọn trước environment/credential checks; mỗi operation là PHP process
riêng, không chuyển profile trong process đang giữ lock. Không dùng lại --schema
cho bước này: --schema yêu cầu schema đã migrated và không thể xác nhận pre-init.
Legacy --environment/--database/--schema giữ nguyên behavior.

### Execution prerequisites và exact commands (REV-024)

Working directory: `api/`. Danh sách đầy đủ
ngay tại đây; không cần tìm biến ở section khác. **Baseline không phải secret**:

```text
APP_ENV=testing
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3307
DB_DATABASE=ecommerce_test
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
ECOMMERCE_MYSQL_CONTAINER_ID=be3ca8665d0b8f1bc0dee25a4bd017b7e6c4c832e07998d98120305e1965a0d5
ECOMMERCE_MYSQL_IMAGE_ID=sha256:a81d043930e51745522bd5c430961cf8286f0a23f0a9a2a6e0108f7ea71b0902
ECOMMERCE_MYSQL_SERVER_UUID=46141f1d-e257-11f1-ab68-66627604040d
ECOMMERCE_MYSQL_EXPECTED_SOURCE_HOST=172.19.0.1
ECOMMERCE_DOCKER_BINARY=<reviewed canonical absolute executable path, not a symlink>
ECOMMERCE_DOCKER_SHA256=<reviewed SHA-256 of that executable>
ECOMMERCE_MYSQL_PREFLIGHT_OPT_IN=1
ECOMMERCE_MYSQL_ALLOW_DB=1
```

DB_CHARSET/DB_COLLATION có defaults tương ứng trong app; nếu đã tồn tại thì phải
khớp baseline. Hai opt-ins cuối chỉ cấp cho process trong commands dưới đây.
HOME phải là absolute local home directory có sẵn của OS user; không đổi HOME để
chạy task. DOCKER_CONFIG phải unset/empty hoặc chính HOME/.docker. Docker daemon
phải local qua Unix socket; PHP macOS 8.3+ có extensions và Composer dependencies
như phần prerequisites. Cần Docker socket permission, không tự đổi Docker context.

**Phải unset/empty** (không phải secrets được phép dùng làm connection config):

```text
DB_USERNAME
DB_PASSWORD
DB_URL
DATABASE_URL
DB_SOCKET
MYSQL_ATTR_SSL_CA
APP_CONFIG_CACHE
APP_ROUTES_CACHE
APP_ENVIRONMENT_PATH
DOCKER_HOST
DOCKER_CONTEXT
TEST_TOKEN
UNIQUE_TEST_TOKEN
ECOMMERCE_MYSQL_MIGRATION_OPT_IN
ECOMMERCE_MYSQL_PRICE_TEST_OPT_IN
```

Không config cache tại api/bootstrap/cache/config.php; reserved path
api/tests/Support/.mysql-no-env phải không tồn tại. Không chạy parallel, có external
writer hoặc dùng checkout khác để né local lock. Không tự unset/đổi biến đang vi
phạm guard: báo BLOCKED để developer chuẩn bị environment phù hợp.

**Secrets — không ghi giá trị vào Git, logs, chat hoặc command arguments**:

| Process | Secret bắt buộc | Secret counterpart phải unset/empty |
|---|---|---|
| --profile=test | ECOMMERCE_MYSQL_TEST_PASSWORD | ECOMMERCE_MYSQL_MIGRATION_PASSWORD |
| --profile=migration | ECOMMERCE_MYSQL_MIGRATION_PASSWORD | ECOMMERCE_MYSQL_TEST_PASSWORD |

Developer cấp secret từ password manager/Keychain bằng cơ chế local an toàn cho
đúng process, không dùng DB_PASSWORD chung hoặc .env thật. Không nạp cả hai profile
credentials vào cùng process. Placeholder Docker path/hash phải được thay bằng
metadata đã review, không tự lấy một executable/server bất kỳ rồi chấp nhận nó.

Commands đề xuất cho **approval execution kế tiếp**, chạy tuần tự trong hai
credential environments riêng. Không commands nào dưới đây đã được chạy:

```bash
ECOMMERCE_MYSQL_PREFLIGHT_OPT_IN=1 ECOMMERCE_MYSQL_ALLOW_DB=1 php -d display_errors=0 -d log_errors=0 tests/Support/mysql-preflight.php --read-only --profile=test
```

```bash
ECOMMERCE_MYSQL_PREFLIGHT_OPT_IN=1 ECOMMERCE_MYSQL_ALLOW_DB=1 php -d display_errors=0 -d log_errors=0 tests/Support/mysql-preflight.php --read-only --profile=migration
```

UUID/source host được pin vào code và environment, không tự học từ connection.
**Đổi server UUID cần explicit review/approval cả MySqlReadOnlyPreflightPolicy::SERVER_UUID
trong code lẫn ECOMMERCE_MYSQL_SERVER_UUID trong execution environment**, cập nhật
regression expectations và xác minh Docker/server identity lại trước execution.
Chỉ đổi env sẽ BLOCKED; không tự sửa constant/env để vượt gate. SOURCE_HOST và account
host cũng cần review riêng khi Docker source IP thay đổi. USER() phải đúng username
profile@172.19.0.1, CURRENT_USER() đúng account đã duyệt. IP không được giả định ổn định.
Preflight opt-in không authorize initialization/preview/apply/integration tests.

### Immutable lazy connection context (REV-023)

Profile, readOnlyPreflight và configureSession được capture by value qua các resolver
closures tới pdoFactory. Khi PDO closure chạy, nó truyền profile/mode tường minh vào
operation/environment/config/identity/session guards; không dùng các mutable static
mode/profile defaults để quyết định SQL hoặc session setup. Credentials/environment
và Docker vẫn được revalidate live, không phải bỏ guard vì có snapshot.

Shared profile/mode defaults chỉ publish sau request gates, environment, effective
config, application bootstrap và resolver checks đều đạt. Failure trước đó không
làm đổi ba defaults; nếu đã giữ lock rồi gặp lỗi, vẫn fail closed và cần process mới,
không tự reset lock/state. Existing initialize/preview/apply/test call sites giữ defaults
API tương thích, còn pending PDO closures luôn dùng snapshot của chính chúng.

Regression tests inspect real factory captures sau khi caller config/static state
đổi; gọi pending closure với opt-ins sai để bảo đảm đúng gate gốc vẫn từ chối trước
credentials/Docker/PDO; application requests sai/APP_ENV cố ý invalid phải không mutate
shared defaults. Tests chỉ thay public env/static state tạm thời và restore bằng
finally, không đọc secret, không gọi preflight operation, không tạo Laravel app hoặc
PDO. Mode-gate tests có APP_ENV=unit-no-connect làm fallback barrier độc lập.

### Read-only SQL inventory và flow

Unique SQL statements trong operation (identity/grants có thể được verify lại trên
cùng PDO; mỗi PDO mới luôn verify riêng):

```sql
SELECT DATABASE() AS db, VERSION() AS version, @@server_uuid AS uuid,
       USER() AS client_identity, CURRENT_USER() AS account, CURRENT_ROLE() AS roles,
       @@GLOBAL.partial_revokes AS partial_revokes,
       @@GLOBAL.skip_name_resolve AS skip_name_resolve,
       @@GLOBAL.mandatory_roles AS mandatory_roles,
       @@GLOBAL.activate_all_roles_on_login AS activate_all_roles;
SHOW GRANTS;
SELECT TABLE_NAME, TABLE_TYPE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
ORDER BY TABLE_NAME;
```

1. Trước bootstrap: validate profile, opt-in preflight=1, UUID/source baseline,
   configureSession=false và absence của init/preview/apply/test opt-ins.
2. Reuse tất cả environment/config/credential/cache/connection guards, Docker
   identity/image/port/network/volume/executable checks và local exclusive lock.
3. Trước mỗi PDO: revalidate request/config và fresh Docker identity. Chỉ sau đó
   mở fixed TCP endpoint/database bằng credential đúng profile.
4. Trước trả PDO: validate USER/CURRENT_USER/CURRENT_ROLE, UUID/version/database,
   skip_name_resolve=1, partial_revokes=0, mandatory_roles rỗng, auto roles=0 và
   exact escaped-scope grants. Không cache DB identity.
5. Query metadata, yêu cầu zero tables/views và không có tên migrations. Không đọc
   records hoặc count rows trong business tables. Metadata visibility dựa trên
   exact database-wide SELECT đã verify; dữ liệu không rõ hoặc malformed => BLOCKED.
6. Disconnect cả success/failure sau connection resolution; output chỉ PASS profile
   summary hoặc generic BLOCKED + non-zero status qua safe exception boundary.

Application được bootstrap bằng read-only mode riêng, **không** gọi session adapter,
SET NAMES, SET sql_mode, beginTransaction hoặc Artisan command. Chỉ SELECT/SHOW,
không migrate:install/migrate --pretend/migrate/seed/PHPUnit. Local lock file có thể
được tạo/giữ như trước; không có database/resource provisioning. Hàm pure
MySqlReadOnlyPreflightPolicy validate request/identity/schema không I/O và được test
bằng synthetic arrays. Regression tests chỉ gọi application với request/environment
sai để xác minh rejection trước bootstrap; không chạy preflight operation hoặc mở PDO.

Read-only mode là reviewed code path, không phải SQL firewall cho PHP tùy ý. Source
providers/bootstrap phải đáng tin cậy như trước; không bảo đảm chống arbitrary PHP
thay handlers, tự mở PDO hoặc ghi trực tiếp. Preflight PASS chỉ là snapshot, không
cấp quyền initialization. Local lock không ngăn external writers. REV-025/016/019 vẫn
được defer, không có cleanup hoặc auto-fix khi FAILED/BLOCKED.

## Operations reference — mỗi lần chạy cần approval riêng

Working directory: `api/`.
Các biến prerequisite phía trên phải có; chỉ cấp password profile cần dùng.

Legacy B1 tester-only (không phải full P3-C; không migration password):

```bash
php -d display_errors=0 -d log_errors=0 tests/Support/mysql-preflight.php --environment
ECOMMERCE_MYSQL_ALLOW_DB=1 php -d display_errors=0 -d log_errors=0 tests/Support/mysql-preflight.php --database
ECOMMERCE_MYSQL_ALLOW_DB=1 php -d display_errors=0 -d log_errors=0 tests/Support/mysql-preflight.php --schema
```

Repository initialization — chỉ sau approval ghi riêng và independent review:

```bash
ECOMMERCE_MYSQL_ALLOW_DB=1 ECOMMERCE_MYSQL_MIGRATION_OPT_IN=initialize php -d display_errors=0 -d log_errors=0 -r 'try { require "tests/Support/mysql-preflight.php"; exit(Tests\Support\MySqlSafety::initializeRepository()); } catch (Throwable) { fwrite(STDERR, "BLOCKED: harness bootstrap failed; details omitted.\n"); exit(1); }'
```

Safety flow:

1. Từ chối opt-in thiếu/sai và test execution opt-in. Không chuyển từ test profile
   sang migration credentials trong cùng process.
2. Guard schema dumps, config cache/URL/socket/hosts, executable/container/port,
   local lock, exact migration identity/grants/roles và session như các operation khác.
3. Kiểm repository config đúng migrations, binding đúng vendor
   DatabaseMigrationRepository và dùng đúng guarded connection. Database phải chưa
   có bất kỳ table/view nào; repository đã tồn tại cũng BLOCKED, không reuse/repair.
   Engine config/default phải tạo InnoDB trước khi cho phép DDL.
4. Kernel command migrate:install phải đúng vendor InstallCommand. Chỉ gọi
   migrate:install --database=mysql --no-interaction; không migrate, seed, reset,
   schema dump load hoặc business migration discovery/execution.
5. Xác minh lại identity và chỉ có một BASE TABLE migrations, InnoDB. Cột id là
   int unsigned auto_increment NOT NULL; migration varchar(255) NOT NULL; batch int
   NOT NULL; PRIMARY KEY(id), không records. Bất kỳ mismatch nào => BLOCKED, không
   drop/reset/retry tự động. Thành công thì disconnect, lock giữ tới hết process.

Đã đối chiếu source Laravel 13 đang cài: Console/Migrations/InstallCommand::handle
chỉ setSource/check/create repository; DatabaseMigrationRepository::createRepository
chỉ định nghĩa ba cột; MigrationServiceProvider cấp binding migration.repository và
truyền cùng repository vào InstallCommand. Không giả định interface có container
alias. Kernel::all()/ContainerCommandLoader được đọc để kiểm command implementation.
Không thực thi các API này trong static validation.

Initialization là DDL có implicit commit: failure sau CREATE có thể để lại bảng.
Không dùng transaction rollback làm recovery. Dừng, xin approval kiểm kê chỉ đọc
và developer quyết định remediation. Local lock không ngăn external clients; cần
exclusive-use window. Nếu repository cần engine/column definition khác, phải review
trước, không tự nới postconditions hoặc sửa vendor/migration.

B2: kiểm source migration trước. `noSchemaDumps()` từ chối mọi entry trong
`api/database/schema`; dump cần review riêng, không tự import. Preview và apply
đều xác minh container/DB bằng migration profile và lock. Lệnh tách rời:

```bash
ECOMMERCE_MYSQL_ALLOW_DB=1 ECOMMERCE_MYSQL_MIGRATION_OPT_IN=preview php -d display_errors=0 -d log_errors=0 -r 'try { require "tests/Support/mysql-preflight.php"; exit(Tests\Support\MySqlSafety::migration(true)); } catch (Throwable) { fwrite(STDERR, "BLOCKED: harness bootstrap failed; details omitted.\n"); exit(1); }'
```

`migrate --pretend` của Laravel có thể tạo migration repository nếu chưa có bảng
`migrations`. Vì vậy harness **từ chối cả preview/apply khi repository chưa tồn tại**.
Trên DB hoàn toàn mới: dừng, xin approval riêng cho operation initialization phía
trên; không bỏ guard hoặc dùng migrate thông thường để vượt blocker. Không có init
tự động. initialize/preview/apply là ba opt-ins và approval gates độc lập.
Preview vẫn thực thi PHP migration; đó không phải sandbox. Phải review migration
source, service calls và SQL ngoài schema builder trước khi duyệt preview.

Chỉ sau developer review preview và approval apply riêng:

```bash
ECOMMERCE_MYSQL_ALLOW_DB=1 ECOMMERCE_MYSQL_MIGRATION_OPT_IN=apply php -d display_errors=0 -d log_errors=0 -r 'try { require "tests/Support/mysql-preflight.php"; exit(Tests\Support\MySqlSafety::migration(false)); } catch (Throwable) { fwrite(STDERR, "BLOCKED: harness bootstrap failed; details omitted.\n"); exit(1); }'
```

Sau B2: bỏ migration credentials, trở lại test profile và chạy lại B1 schema check.
Không dùng `artisan migrate` trực tiếp, composer setup/test, reset/fresh hoặc dump load.

C (chỉ test profile): chạy lại B1 rồi chạy riêng một lệnh PHPUnit được duyệt:

```bash
ECOMMERCE_MYSQL_ALLOW_DB=1 ECOMMERCE_MYSQL_PRICE_TEST_OPT_IN=1 ./vendor/bin/phpunit -c phpunit.mysql.xml --testsuite MySql --group mysql
# Ví dụ alternative được duyệt: direct file + filtered run, vẫn giữ cùng config
ECOMMERCE_MYSQL_ALLOW_DB=1 ECOMMERCE_MYSQL_PRICE_TEST_OPT_IN=1 ./vendor/bin/phpunit -c phpunit.mysql.xml tests/MySql/ProductPriceBigIntTest.php --filter test_listing_pagination_and_sorting_preserve_unsigned_maximum
```

## REV-017 — Safe operation boundary

initializeRepository(), migration() và CLI preflight dùng chung safeOperation.
Private implementations trả status/buffered command output, không tự fwrite output.
Boundary bắt Throwable, không tin exception class/prefix/message/code/previous/trace
và không in chúng. Exception => status 1. Command trả mã 1..255 => giữ nguyên mã,
loại command output và chỉ in BLOCKED cố định theo operation. Callback result sai
định dạng => thất bại. Không gọi exception renderer/report/logger hoặc echo raw SQL
khi có lỗi. Không automatic retry/cleanup, không thay đổi approval gates/opt-ins.

Trong operation, PHP display_errors/log_errors tạm tắt; incidental echo/print được
buffer và discard. PHP settings và buffer level được khôi phục khi hoàn tất. Chỉ
command output được trả tường minh khi status=0/postconditions đạt mới được xuất.
SQL của preview **thành công** vẫn cần hiển thị để review; exception/error output
chứa SQL/connection details không được in. Luôn exit bằng return code method.

Đã đọc vendor Foundation Console Kernel::call và Console Application::call: call()
dùng BufferedOutput và Application đặt setCatchExceptions(false), nên command
exceptions truyền về boundary thay vì phải tin exception renderer. CLI preflight
cũng dùng cùng boundary, không còn in message chỉ vì prefix là MySQL safety.
Modes --environment/--database/--schema giữ nguyên tính chất đọc; --read-only dùng cùng boundary cho cả hai profiles.

Invocation php -r phía trên có outer catch quanh require và PHP -d flags để lỗi
bootstrap trước khi class được nạp cũng không in message/trace. Không dùng lệnh cũ
không có bootstrap boundary. Parser tests import declarations; regression tests bổ sung chỉ đi tới rejected
request gates trước application bootstrap. Không chạy operation boundary/preflight
hoặc tạo PDO.

Đây không phải PHP sandbox: arbitrary provider/migration tự fwrite tới STDOUT/STDERR,
đổi handlers/buffers/ini, exit hoặc fatal không recoverable không được chặn tuyệt đối.
Chỉ chạy source đã review; không xóa logs cũ. Lỗi sau DDL có thể để lại state: dừng,
xin approval inventory chỉ đọc, không tự drop/reset. Runtime success paths đã được chạy ở P3-C/P3-D/P3-E/P3-F; chưa cố ý gây lỗi
sau DDL để kiểm thử toàn bộ failure paths.

## REV-018 — Additional isolated parser cases

Thêm rejection datasets cho CREATE TEMPORARY TABLES, LOCK TABLES, CREATE VIEW ở cả
hai profiles; PROCEDURE/FUNCTION-level grants; mixed-case privilege tokens; leading
space/tab; migrator table-level/role/WITH ADMIN OPTION grants; authenticated hoặc
recipient host localhost và host sai. Dùng dữ liệu synthetic + PHPUnit Framework
TestCase, không Laravel/DB/Docker. Các cases này nằm trong isolated suite đã đạt 204 tests/415 assertions.

## Test matrix và sorting fix

Gate C đã chạy đủ 22 executions: listing/details mỗi endpoint 5 literal strings: `0`,
`9007199254740993`, `9223372036854775807`, `9223372036854775808`,
`18446744073709551615`; SELECT CAST xác minh storage rồi strict assertSame JSON.
Public fields/published filtering, draft/archived 404, missing/non-numeric/JSON 404,
pagination/order, empty listing và ba invalid pagination datasets được giữ lại.

Product timestamps được gán trực tiếp trước save, không qua fillable; SELECT kiểm
stored created_at/updated_at bằng chính xác fixture input. Sorting tạo lần lượt:
A(newest), B(mid), C(mid), D(oldest, unsigned maximum). Expected A,C,B,D; id DESC
đơn thuần cho D,C,B,A. Timestamp DESC đặt A trước D, secondary id DESC đặt C trước
B. Trang hai chứa B,D và giữ nguyên maximum price string.

Trước fixture: kiểm InnoDB, unsigned price, FKs, migrations và products/sellers rỗng.
Fixture transaction/rollback, không migration hoặc truncate; auto-increment vẫn có
thể tăng sau rollback. Dữ liệu tồn dư => BLOCKED, không cleanup tự động.

## Static validation và Quality Gate

```bash
php -l tests/Support/mysql-preflight.php
php -l tests/Unit/MySqlGrantParserTest.php
vendor/bin/pint --test tests/Support/mysql-preflight.php tests/Unit/MySqlGrantParserTest.php
git diff --check
```

Chỉ isolated pure unit suite dưới đây được phép trong implementation P3-C; không
bootstrap Laravel/DB và không chạy preflight. Runtime MySQL/init/preview/apply và
integration tests vẫn cần approval riêng:

```bash
vendor/bin/phpunit -c phpunit.xml tests/Unit/MySqlGrantParserTest.php --fail-on-skipped --fail-on-risky --fail-on-warning
```

PASS chỉ khi approved tests thực chạy đạt, isolation có evidence, static checks và
SQLite regression được duyệt đạt; không skipped/risky/warning. FAIL khi lỗi test;
BLOCKED khi thiếu approval/environment/grants/schema; INCOMPLETE khi thiếu execution.
Gate A ready for review không cấp quyền B1/B2/C hoặc commit.

## Giới hạn và deferred findings

- **REV-004:** opt-in không là authorization; harness không phải PHP/network sandbox.
  PHP tùy ý có thể new PDO, thay resolver, gọi shell hoặc sửa guard. Chỉ chạy code đã
  review. Include-time code, static hooks và data providers có thể chạy trước suite
  guard; data providers phải thuần dữ liệu, không I/O. Không tuyên bố complete protection.
- **REV-005:** Docker path/hash và lock được tăng cường, nhưng TOCTOU/admin tampering,
  parent-directory changes, client ngoài harness, checkout khác hoặc máy khác không
  bị khóa này ngăn chặn. Distributed locking defer; developer bảo đảm exclusive use.
- **REV-007:** host/port/database/version tập trung thành constants trong PHP; XML là
  defaults độc lập và guard sẽ reject drift. Pin macOS/MySQL 8.4.13 theo scope hiện tại;
  mở rộng Linux/CI/version matrix defer tới approval môi trường riêng, không tự nới guard.
- B1 SHOW GRANTS kiểm policy nghiêm ngặt, không là general-purpose MySQL grant parser.
  Schema guard không thay full schema diff. B2 privileges vẫn có DDL trên test DB,
  migration PHP và named volume cần review. Identity checks không ngăn môi trường
  bị administrator thay đổi giữa hai lần kiểm tra.

## Deferred review findings

- **REV-025:** chưa kiểm kê routines/triggers/events. Zero tables/views không phải
  bằng chứng database không có mọi loại schema objects; không tự mở rộng SQL inventory.

- **REV-019:** chưa thêm repository table collation verification. Session charset/
  collation guard không thay thế việc kiểm repository collation.
- **REV-016:** Docker subprocess vẫn đọc stdout rồi stderr tuần tự; nguy cơ pipe
  deadlock còn deferred. Không sửa subprocess I/O hoặc Docker resources trong vòng này.

## REV-011 — Least privilege và migration discovery (assessment only)

Đã đọc các migration trong api/database/migrations: up() hiện tạo bảng/index/FKs,
không thấy DML UPDATE/DELETE hoặc DROP. down() có dropIfExists nhưng không thuộc
forward migrate được duyệt. Repository cần SELECT/INSERT để đọc/ghi migration
records. Đề xuất provisioning cho forward migrations hiện tại chỉ cấp tập cần
thiết từ SELECT, INSERT, CREATE, ALTER, REFERENCES; xác nhận bằng SQL preview
và MySQL privilege checks ở gate riêng. Không cần DROP/DELETE/UPDATE chỉ để hỗ trợ
rollback/reset không được duyệt. Parser hiện enforce đúng tập forward privilege đã duyệt, không chấp nhận DROP,
DELETE, UPDATE hoặc INDEX. Grammar dùng ALTER TABLE ADD index/unique/FK, không
standalone CREATE INDEX. Không thay quyền account để vượt parser/runtime failure.

BaseCommand::getMigrationPaths() ghép migrator->paths() với database/migrations;
Migrator::getMigrationFiles() có thể nhận package paths. Schema guard hiện chỉ
kiểm app migration names, nên không chứng minh mọi package migration đã applied.
AppServiceProvider hiện không đăng ký migration paths; Sanctum dùng publishesMigrations,
không tự loadMigrationsFrom trong provider đã kiểm tra. Không suy ra runtime paths
luôn rỗng: trước B2 phải kiểm runtime migrator->paths(), package/provider versions
và danh sách files thực tế, không require migration để phân tích. Package paths
mới hoặc trùng migration names => BLOCKED để review thêm. Mở rộng discovery/guard
code defer ngoài scope vòng này. P3-E Recovery/P3-F đã kiểm runtime paths và chỉ
preview/apply 13 application migrations, 0 package migrations theo approval riêng.

## REV-012 — Transaction escape (assessment only)

DB::commit(), raw COMMIT/DDL, PDO commit hoặc disconnect có thể làm dữ liệu thoát
transaction; SELECT/INSERT-only không cấm COMMIT. Callback rollback chỉ rollback
transaction còn mở, không thể xóa dữ liệu đã commit. Test sau có thể BLOCKED vì
dữ liệu tồn dư, nhưng điều đó không khôi phục trạng thái DB. Không cleanup tự động.
Artisan commands có thể chạy code ngoài fixture; thiếu DDL grants chỉ chặn một số
lệnh, không ngăn mọi write. setUpBeforeClass/attributes/static hooks, include-time
code và providers có thể chạy trước instance setUp guard; review code là bắt buộc.

Khuyến nghị follow-up: phát hiện transactionLevel/PDO inTransaction bất thường ở
teardown, gắn instrumentation để báo early commit, và review/hạn chế artisan/static
hooks. Chỉ phát hiện, không bảo đảm hoàn tác hoặc ngăn malicious PHP. Chưa thêm
behavior này trong scope hiện tại. Khi nghi transaction escape: dừng, báo BLOCKED,
kiểm kê dữ liệu bằng read-only approval riêng, developer quyết định remediation.

## Trạng thái sau Gate C và lần chạy tiếp theo

P2 provisioning, P3-C preflight, repository initialization, migration preview/apply
và Gate C đã hoàn tất theo approval riêng. Harness đã xác minh schema và exact
grants tại runtime; kết quả chi tiết ở phần Trạng thái.

Manifest local `api/storage/framework/testing/p3e-preview-manifest.json` lưu relative
paths, SHA-256, preview timestamps, UUID/database, SQL summary và postconditions.
Manifest được Git bỏ qua, mode 0600. P3-F đã đối chiếu hash của manifest và cả 13
migration files trước apply. Manifest mô tả trạng thái preview trước apply, không
thay cho kiểm tra DB live và không cấp quyền replay.

Lần chạy tiếp theo phải có approval và revalidate Docker/server/account/schema/data.
Không initialization lại trên schema hiện có. Thay đổi package paths, migration
hashes hoặc baseline cần review mới. Không tự cleanup, migration hoặc PHPUnit.
