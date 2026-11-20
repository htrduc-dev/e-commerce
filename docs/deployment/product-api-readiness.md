# Product APIs — Production Readiness Backlog

Ngày lập và đối chiếu GitHub evidence: **2026-11-19**.

Baseline remote main: `3eedd5044c8133fcfbc82413528798ec4203e9d4`.

**Production decision: NO-GO.** Các P0 production gates bên dưới chưa đóng.
Project đang phát triển local; deployment platform và infrastructure topology chưa
được chọn. Tài liệu này ghi backlog và điều kiện nghiệm thu, không cấp quyền triển
khai hạ tầng, chạy tests lên dịch vụ thật hoặc deploy.

## 1. Scope và trạng thái phát triển

Phạm vi là readiness của GET /api/products và GET /api/products/{id}, đặc biệt
REV-002 rate limiting. Không mở rộng Product business features hoặc triển khai fixes.

**Local feature-development readiness:** có thể tiếp tục business-feature development
theo workflow và approval riêng, dùng môi trường local/test đã xác minh isolation.
Production blockers không tự chặn công việc local không phụ thuộc production.

**Production deployment readiness:** chưa đạt; passing local tests/CI không chứng
minh production topology, shared-cache consistency hoặc trusted-proxy correctness.

Chỉ dùng bốn status categories sau cho implementation/backlog:

| Category | Ý nghĩa |
| --- | --- |
| Implemented and verified | Đã có bằng chứng cho đúng scope, phiên bản và môi trường được ghi |
| Known limitation | Hành vi/giới hạn đã biết; không đồng nghĩa risk được chấp nhận cho production |
| Not yet verified | Chưa có evidence đủ để xác nhận một yêu cầu |
| Deferred until deployment architecture is selected | Chủ động hoãn quyết định/implementation; vẫn outstanding |

Category phải luôn đi cùng phạm vi bằng chứng. Một feature có local evidence thuộc
Implemented and verified không tự làm production gate tương ứng đạt.
TBD nghĩa là chưa quyết định, không phải default được chấp thuận.

## 2. Product API features và merged PRs

| PR | Nội dung | Merge commit trên main | Category | Evidence scope |
| --- | --- | --- | --- | --- |
| [#1](https://github.com/htrduc-dev/e-commerce/pull/1) | Product Listing/Details, public resource, AI development workflow và PHP/SQLite CI | `82a3d5387a8ab872f05aeeba2c1da856688df661` | Implemented and verified | Source/merge metadata; local SQLite execution reports; GitHub Actions SQLite CI. Production: Not yet verified. |
| [#2](https://github.com/htrduc-dev/e-commerce/pull/2) | Guarded MySQL integration harness, price tests, testing agents/workflow | `11b316ce205e9708104d574843d5dd437def8557` | Implemented and verified | Source/merge metadata; local MySQL/harness unit execution reports; GitHub Actions SQLite CI. Không phải production evidence. |
| [#3](https://github.com/htrduc-dev/e-commerce/pull/3) | REV-003 seller visibility: published product và active seller | `c9d3d5d586c48c27663239c4c5c40f6ea691a96e` | Implemented and verified | Source/merge metadata; local SQLite và local MySQL execution reports trước REV-002; GitHub Actions SQLite CI. Production: Not yet verified. |
| [#4](https://github.com/htrduc-dev/e-commerce/pull/4) | REV-002 public Product rate limiting, quota validation 1–10000 | `3eedd5044c8133fcfbc82413528798ec4203e9d4` | Implemented and verified | Source/merge metadata; local SQLite execution reports và GitHub Actions SQLite CI. MySQL với REV-002 và production: Not yet verified. |

GitHub metadata xác nhận cả bốn PR đã merged/closed; lịch sử được giữ bằng merge commits.
Category trong bảng chỉ áp dụng evidence scope đã ghi; historical execution reports
không được reviewer kiểm chứng độc lập. Không dòng nào xác nhận production readiness.
Implementation hiện có:

- Chỉ published products thuộc active seller xuất hiện công khai; hidden Details
  trả 404. Pagination chỉ tính visible products.
- Public fields giữ id, name, slug, price; price là JSON string, giữ exact integer.
- Listing giữ created_at DESC, id DESC, pagination và validation hiện hữu.
- Product rate limiter được gắn riêng vào Product routes; Auth/health routes
  không dùng named limiter này.
- CI/default PHPUnit tách khỏi MySQL integration suite. Không tự migration/reset
  hoặc cấp quyền database từ test setup.

Đây là xác nhận source và kiểm thử lịch sử, không phải production deployment evidence.
Contract chi tiết nằm trong [Product API documentation](../api/products.md).

## 3. Historical test evidence

Các số dưới đây là **historical execution reports**, được báo cáo trong development/
review workflow; reviewer chưa kiểm chứng độc lập các test/assertion counts. Không
chạy lại PHPUnit/MySQL hoặc tải lại assertion counts từ CI logs khi tạo/sửa tài liệu.
PASS trong bảng là kết quả được báo cáo, không phải một lần execution mới hay
independent attestation. Không cộng các dòng: suites chồng lấp, khác thời điểm/scope.

| Giai đoạn / scope | Tests | Assertions | Kết quả lịch sử | Category | Evidence scope / provenance |
| --- | ---: | ---: | --- | --- | --- |
| Isolated MySQL harness/parser unit suite sau P3-C fixes | 204 | 415 | PASS | Implemented and verified | Local pure unit execution report; chưa độc lập xác minh counts. |
| Gate C ban đầu, trước REV-003 seller visibility | 22 | 107 | PASS | Implemented and verified | Local dedicated MySQL execution report; trước REV-003 và REV-002. |
| REV-003 SQLite default Unit/Feature, sau REV003-001 fix | 262 | 918 | PASS | Implemented and verified | Local SQLite execution report; chưa độc lập xác minh counts. |
| REV-003 dedicated local MySQL suite | 31 | 185 | PASS | Implemented and verified | Local dedicated MySQL execution report; trước REV-002, không có limiter hiện tại. |
| REV-002 rate-limit + Unit verification trước upper-bound fix | 234 | 780 | PASS, historical intermediate scope | Implemented and verified | Local SQLite/ArrayStore + pure Unit execution report; intermediate REV-002. |
| REV-002 focused rate-limit suite sau REV002-002 fix | 39 | 494 | PASS | Implemented and verified | Local isolated SQLite/ArrayStore execution report; không phải production backend. |
| REV-002 full default Unit/Feature regression | 301 | 1412 | PASS | Implemented and verified | Local SQLite/ArrayStore execution report; không phải MySQL integration hoặc production. |

Latest full regression bao gồm Product Listing **36 tests**, Product Details
**20 tests** và rate-limit **39 tests**. Các runs PASS nêu trên không có failures,
errors, skips, risky tests hoặc warnings theo báo cáo verification tương ứng.

REV-003 MySQL verification dùng dedicated test environment, tester SELECT/INSERT,
guarded bootstrap/connection và transaction rollback. Sau test: 19 expected tables,
13 migration records, schema/indexes/FKs và server identity giữ nguyên; không còn
fixtures trong application tables. Đây không phải production database inspection.

**Giới hạn evidence:** MySQL 31/185 chạy cho REV-003 trước REV-002. Chưa chạy lại
MySQL integration suite với rate limiter REV-002; default SQLite CI không thay thế
lần kiểm tra đó. Future MySQL execution vẫn cần approval và mọi existing safety gates.

**GitHub Actions CI metadata** dưới đây đã được đối chiếu riêng: HEAD, conclusion
và liên kết run. Việc này không độc lập xác minh số tests/assertions từ job logs,
không xác nhận local MySQL counts và không xác nhận production behavior:

| PR | Run | Verified HEAD | GitHub conclusion |
| --- | --- | --- | --- |
| #1 | [44268347859](https://github.com/htrduc-dev/e-commerce/actions/runs/44268347859) | `5ff06f007fafc7d668dc87f9e35e4323e61672f2` | success |
| #2 | [44269460121](https://github.com/htrduc-dev/e-commerce/actions/runs/44269460121) | `eafe2b677f421ad472c7b2292ee2a8e2fd585bc8` | success |
| #3 | [44440584760](https://github.com/htrduc-dev/e-commerce/actions/runs/44440584760) | `a9633b8b73124f9cf0fabe83f709c754beb1c293` | success |
| #4 | [44476488940](https://github.com/htrduc-dev/e-commerce/actions/runs/44476488940) | `68e1de1d3ff3665cf42c75bdf3eaafcf0707e9c2` | success |

CI kiểm Composer/platform, package discovery, SQLite isolation, PHP syntax, Pint và
default PHPUnit trên macOS/PHP 8.5. Nó không kiểm production ingress, cache backend,
multi-instance hoặc deployment smoke tests. Workflow chạy theo pull_request,
không phải kiểm thử production sau merge. **Passing CI does not establish production readiness.**

## 4. Rate-limiting behavior và configuration hiện tại

Category: **Implemented and verified** cho application behavior/local tests.

- Named limiter: public-products.
- Default: 60 requests/phút/client IP; Listing, Details và HEAD dùng chung budget.
- PRODUCT_API_RATE_LIMIT_PER_MINUTE phải là integer hoặc integer string hợp lệ từ
  1 đến 10000 inclusive. Malformed/out-of-range configuration gây lỗi, không unlimited.
- Key logic dùng namespace ecommerce-api:public-products:v1 và SHA-256 của
  Request::ip() sau Laravel trusted-proxy processing. Không dùng product ID/query
  để cấp budget mới. Unknown IP dùng finite fallback bucket.
- Requests tới throttle, gồm relevant numeric-product 404/validation 422, tiêu thụ
  quota. Request không match route có thể không đi qua limiter.
- 429 JSON: `{"message":"Too many requests."}`, giữ Laravel rate-limit headers và
  Cache-Control chứa no-store. Client phải tôn trọng Retry-After/backoff.

Laravel resolve RateLimiter cache qua cache.limiter, hoặc default cache khi entry
đó không có. [Cache config hiện tại](../../api/config/cache.php) chưa khai báo
cache.limiter; vì vậy dùng CACHE_STORE, mặc định source là database.
Không có production runtime value đã xác minh. Không giả định một environment
variable mới tự có tác dụng nếu source chưa mapping nó.

Limiter từ chối failover/null; array chỉ được chấp nhận trong testing. Guard này
không chứng minh mọi driver còn lại là shared hoặc phù hợp production. Không thêm
custom outage handler hoặc tự chuyển sang unlimited/process-local fallback.

## 5. Known limitations và accepted implementation trade-offs

Category: **Known limitation**.

- ArrayStore/mock tests chứng minh application behavior, không chứng minh store thật.
- Local file, worker-local hoặc node-local state không bảo đảm quota toàn deployment.
- Trong RateLimiter::increment(), cache increment trả false bị cast (int) thành 0.
  Nếu precheck chưa chặn, middleware không từ chối request chỉ vì hit trả 0; đây là
  potential fail-open/undercount path, khác với cache exception được truyền lên handler.
- Admission check và increment tách bước; có thể overshoot dưới concurrency.
  Chưa đo upper bound, không bảo đảm overshoot luôn nhỏ.
- TTL window có thể cho burst; quota không phải một request mỗi giây.
- IPv6/IP rotation có thể né per-address limits; NAT có thể làm nhiều người chia quota.
- Deterministic IP hash không được coi là hoàn toàn anonymous.
- Exception responses 404/422 không được bảo đảm có đủ rate-limit headers.
- Framework rate limiting không thay edge DDoS/abuse protection.

Các trade-offs trên đã được giữ rõ trong implementation review
APPROVE WITH FOLLOW-UPS. REV002-002 resolved bằng upper bound 10000.
Điều này **không phải blanket production risk acceptance**. Developer/security phải
ghi quyết định và evidence tương ứng trước khi đóng P0 gates. Không có code defect
production mới được xác nhận chỉ từ thiếu deployment information.

## 6. Shared-cache requirements

Category: **Deferred until deployment architecture is selected**.
Production backend/topology: **TBD**.

- Mọi API instance/workers cùng môi trường phải dùng counter store nhất quán và
  namespace chung; môi trường/ứng dụng khác phải tách namespace.
- Sticky sessions không thay shared counters. Không chấp nhận local file/array làm
  bằng chứng multi-instance consistency.
- Xác minh TTL, write/read consistency, eviction, failover, connection timeouts,
  capacity và permissions cho backend được chọn.
- Database cache là lựa chọn có cấu hình trong source, không phải backend production
  đã duyệt. Cần xác minh cache tables và quyền/capacity; counter có reads/writes/locking,
  có thể tăng tải lên shared business DB.
- Không giả định Redis sẵn có. Nếu chọn Redis/Memcached/backend khác, cần kiểm tra
  client/extension, consistency và operational behavior thật.
- Không mở rộng quyền MySQL tester SELECT/INSERT để chạy cache-counter operations.
- Nếu bổ sung dedicated limiter store, CI và MySQL harness phải được xác minh vẫn
  resolve actual ArrayStore trong test isolation; không chỉ kiểm cache.default.

Không dùng Cache::flush() trên shared environments để kiểm thử. Kiểm chứng cache cần
dedicated test resources/namespace, TTL hữu hạn và approval riêng.

## 7. Trusted proxies, client IP và origin access

Category: **Deferred until deployment architecture is selected**.
Ingress/proxy chain, CIDRs và header contract: **TBD**.

- Ghi sơ đồ client → edge/CDN/WAF → load balancer/proxy → application.
- Chỉ trust verified proxy IP/CIDR và đúng forwarded-header contract; không tự trust *.
- Proxy phải sanitize/overwrite client-supplied forwarded headers.
- Xác minh origin không cho bypass approved ingress controls; allowed hosts và
  direct-origin exposure phải được review theo topology thực tế.
- Trust sai có thể cho spoof IP; thiếu trust đúng có thể gom mọi client thành IP ingress.
- Laravel vendor có auto-trust behavior ở một số hosted environments; chưa cấu hình
  explicit proxies không đồng nghĩa client IP đã an toàn.
- Nếu browser cross-origin cần đọc Retry-After/X-RateLimit headers, review CORS
  exposure theo nhu cầu thật, không tự đổi configuration.

[Bootstrap hiện tại](../../api/bootstrap/app.php) chưa khai báo explicit proxy policy.
Không ghi CIDRs hoặc vendor-specific config giả định vào backlog.

## 8. Cache outage và concurrency decisions

Category: **Not yet verified**.
Production outage policy và overshoot tolerance: **TBD**.
**G04 đang bị chặn bởi prerequisites của G02**: chưa chọn/xác minh backend và contract
cần thiết thì chưa thể bắt đầu kiểm chứng behavior outage/concurrency đại diện.

Code hiện tại không catch cache exceptions để cho request đi tiếp hoặc đổi lỗi hạ
tầng thành quota-exhaustion 429. Exceptions thông thường tới error handler dưới dạng
5xx; exception-based failure không phải quota exhaustion và không chủ động cho
controller chạy tiếp.

Cơ chế false-return cụ thể trong Laravel RateLimiter::increment():

- Framework gán $hits = (int) $this->cache->increment($key, $amount).
- Nếu increment trả false thay vì ném exception, cast biến false thành 0.
- Khi tooManyAttempts() trước đó chưa chặn, ThrottleRequests vẫn gọi downstream sau
  hit(); nó không chặn chỉ vì hit() trả 0. Request có thể được xử lý mà lượt truy cập
  không được ghi nhận đúng: **potential fail-open path**. Mức ảnh hưởng phụ thuộc
  backend/state; không khẳng định mọi false đều gây unlimited hoặc production đã lỗi.
- Khác với increment failure, add() trả false có thể chỉ là key đã tồn tại bình thường.
  G04 phải kiểm thử/phân biệt các trường hợp, không coi mọi false là outage.

Cần quyết định bằng văn bản:
- Khi không xác nhận được counter, fail-closed response/status, retry policy và
  availability expectations là gì? Không có quyết định tự fallback unlimited.
- Tolerance cho overshoot/burst là bao nhiêu? Có cần strict atomic admission không?
- Chấp nhận counter loss do eviction/restart/failover tới mức nào?
- Clock synchronization và Retry-After/Reset correctness được kiểm chứng thế nào?

Nếu cần strict enforcement, phải review một admission operation atomic có kết quả
được tôn trọng. Chỉ đổi store sang Redis không tự chứng minh middleware đã atomic.
Custom outage/counter implementation cần scope và approval riêng, không patch vendor.

## 9. IPv6 và shared-IP considerations

Category: **Known limitation**.
Remediation policy: **Deferred until deployment architecture is selected**.

Current policy là per-address IP, không normalize IPv6 /64. Cần traffic evidence về
IPv6 rotation, mobile clients, carrier-grade NAT và shared office IPs. Prefix
aggregation có thể giảm một dạng rotation nhưng cũng ảnh hưởng fairness; không tự
chọn /64 hoặc tăng quota. Distributed clients vẫn có thể né per-IP protection.

Owner business/security cần chấp thuận residual risk hoặc duyệt remediation; đây
không phải lý do đánh dấu production gate đạt mà chưa có quyết định.

## 10. Monitoring, alerting và runbook requirements

Category: **Not yet verified**.
Platform, SLOs, alert thresholds, retention và on-call owner: **TBD**.

Tối thiểu cần:
- Request rate/status theo route pattern; tách 429, relevant 4xx và cache-related 5xx.
- Allowed/throttled counts; phân biệt edge throttling và application throttling.
- Endpoint/cache latency percentiles, cache errors/timeouts/evictions/saturation;
  database cache locking/contention nếu backend đó được chọn.
- Traffic phân phối giữa instances và unknown-IP fallback usage.
- Alerts có owner, routing, escalation và kiểm thử delivery. 429 tăng không tự đồng
  nghĩa incident; phải đối chiếu abuse, NAT fairness và user impact.

Laravel exception reporting không tự bảo đảm ghi mọi 429. Repository chưa có Product
metrics instrumentation hoặc custom cache-readiness listener đã xác minh.
Logging config hiện có không chứng minh centralized collection/alerting đang hoạt động.
Health route /up không được coi là shared-cache readiness check.

Runbook cần stop conditions, triage quota/cache/proxy errors, change approval,
rollout/rollback và giữ minimum abuse protection khi recovery. Không coi tắt limiter
là fallback được duyệt. Logs chỉ ghi metadata cần thiết, không credentials/bodies/raw
keys; không dùng raw IP/hash làm high-cardinality metric labels mặc định.

## 11. Production-specific verification requirements

Category: **Not yet verified**.

Future verification phải được duyệt riêng và chạy trên isolated non-production
environment đại diện cho backend/topology đã chọn. Tài liệu này không cho phép kết nối
staging hoặc production hiện tại.

| Verification | Acceptance / evidence |
| --- | --- |
| Effective deployment config | Sanitized manifest: release, PHP/extensions, limiter/store/prefix, quota và proxy contract đúng bản duyệt |
| Multi-instance | Cùng client bị tính chung quota khi requests đi qua nhiều instances/workers |
| Backend TTL/consistency | Recovery/reset headers đúng tolerance; không tự cấp budget mới ngoài policy |
| Outage/failure injection | Exception, timeout, relevant false-return, eviction/failover theo approved policy; không silent unlimited |
| Concurrency/load | Đo admitted/throttled/overshoot và latency; đạt tolerance/SLO được duyệt |
| Proxy/origin | Trusted/untrusted hops, spoofed headers và direct-origin controls đúng contract |
| Monitoring | Metrics/logs có dữ liệu đúng, không secrets; alerts tới đúng owner |
| API regression | Product visibility, fields, price strings, validation/pagination/sorting giữ nguyên |
| Guarded MySQL regression với REV-002 | Chạy lại existing guarded MySQL integration suite với limiter REV-002 hiện tại, chỉ sau approval riêng và isolation/schema/permissions verification; lưu actual counts, release SHA và post-test integrity. Historical 31 tests / 185 assertions chỉ là pre-REV-002 evidence, không đóng yêu cầu này |
| Operational release | Artifact/config cache/readiness/rollback procedure được review và rehearsal |

Không tự chọn SLO hoặc load thresholds. Không dùng shared MySQL test database với
quyền bị broaden để giả lập cache production. Nếu cần backend tests mới, phải có
isolation harness và execution approval riêng; không bypass existing MySQL guards.

## 12. Prioritized remediation backlog

Mọi P0 chưa đạt là **deployment blocker**, kể cả khi status là Deferred.
Dependencies dưới đây là các approved decisions/contracts; việc đóng gate còn cần
evidence thực thi, không chỉ một tài liệu quyết định.

| Gate / priority | Category hiện tại | Responsible role | Dependencies | Acceptance criteria | Evidence requirements |
| --- | --- | --- | --- | --- | --- |
| G01 / P0 — Deployment architecture | Deferred until deployment architecture is selected | Developer + Infra | Business deployment decision | Platform/runtime, instance/worker/region model, ingress và ownership được duyệt | Architecture diagram + sanitized deployment contract, reviewer/date |
| G02 / P0 — Shared counter backend | Deferred until deployment architecture is selected | Infra + Backend + DBA nếu cần | G01 architecture contract | Store/namespace/permissions/TTL/capacity phù hợp; counters dùng chung giữa instances | Sanitized effective config, access review, dedicated-backend cross-instance test results |
| G03 / P0 — Trusted client IP | Deferred until deployment architecture is selected | Infra/Security + Backend | G01 ingress/origin contract | Proxy CIDRs/header sanitization/origin controls đúng; spoof không đổi bucket | Approved proxy diagram/config review + trusted/untrusted/direct-origin verification |
| G04 / P0 — Outage/concurrency policy | Not yet verified | Developer/Security + Backend + Infra | Bị G02 prerequisites chặn; cần backend decision/contract trước khi bắt đầu verification | Outage response, increment false → (int) 0 potential fail-open, counter-loss và overshoot tolerance được duyệt và kiểm chứng | Decision record + exception/timeout/false-return/concurrency results phân biệt failure và add-existing-key bình thường |
| G05 / P0 — Observability/on-call | Not yet verified | Infra/SRE + Backend | G01 platform, G04 policy | Metrics/alerts/latency visibility và response owner đủ để phát hiện, xử lý lỗi | Dashboard/query references, tested alert delivery, redacted log examples, runbook review |
| G06 / P0 — Representative environment verification | Deferred until deployment architecture is selected | Testing + Backend + Infra | Approved G01–G05 contracts | Production-specific matrix ở section 11 đạt, gồm rerun guarded MySQL suite với REV-002 sau approval/isolation; Product contracts không regression | Approved test plan, release SHA, environment fingerprint, actual MySQL results và post-test integrity; không thay bằng historical 31/185 |
| G07 / P0 — Release go/no-go | Not yet verified | Developer/release owner + Security/Infra | G01–G06 evidence; G08 có documented risk-acceptance hoặc remediation decision, không bắt buộc đã hoàn tất optional implementation | P0 gates đóng; quyết định G08 rõ ràng, không coi deferred code là passed; rollout/stop/rollback và production permission được phê duyệt | Signed/recorded go/no-go, owners/date, release/runbook references |
| G08 / P1 — IPv6/NAT/abuse fairness | Known limitation | Business/Security + Backend | G01 edge/client information, traffic evidence | Risk được chấp thuận hoặc remediation có scope/tests; không coi deferred code là passed | Traffic analysis, residual-risk decision hoặc approved remediation evidence |

G07 chỉ cần G08 có quyết định rủi ro được ghi nhận (chấp nhận residual risk hoặc chọn
khắc phục cùng điều kiện release). Không yêu cầu mọi optional G08 implementation
đã hoàn tất. Nếu quyết định yêu cầu remediation trước release, phải có evidence đạt
điều kiện đó trước GO; không dùng việc defer để bỏ qua điều kiện đã duyệt.

Tất cả owner cụ thể và deadlines: **TBD**. Roles chỉ là trách nhiệm đề xuất, không
phải cá nhân đã nhận việc. Khi chọn architecture, cập nhật proposed application/
infrastructure files và verification commands trong một implementation plan riêng.
Không invent IaC/proxy filenames khi deployment platform còn TBD.

## 13. Outstanding deployment architecture decisions

Category: **Deferred until deployment architecture is selected**.

| Quyết định cần developer/infra cung cấp | Giá trị hiện tại | Gate |
| --- | --- | --- |
| Platform, OS/PHP runtime, extensions, PHP-FPM/Octane process model | TBD | G01 |
| Instances/workers/regions/autoscaling, release method | TBD | G01 |
| Edge/CDN/WAF/load balancer/proxy chain và ingress controls | TBD | G01, G03 |
| Trusted proxy CIDRs, forwarded headers, allowed-host/origin contract | TBD | G03 |
| Cache backend/topology/client, same-environment namespace, cross-environment separation | TBD | G02 |
| TTL/eviction/failover/timeouts, permissions/capacity/ownership | TBD | G02, G04 |
| Outage behavior, overshoot/burst tolerance và clock expectations | TBD | G04 |
| Traffic/client/NAT/IPv6 profile, quota suitability | TBD | G04, G08 |
| Monitoring platform, retention/privacy, latency/availability SLOs, alert routing/on-call | TBD | G05 |
| Test environment, rollout/stop/rollback/recovery approvals | TBD | G06, G07 |

Chỉ yêu cầu sanitized metadata và decision evidence; không gửi passwords, tokens,
secret file content hoặc production credentials cho coding agent.

## 14. Production go/no-go checklist

**Hiện tại: NO-GO cho production. Local business-feature development có thể tiếp tục.**

- [ ] G01 architecture/deployment contract được phê duyệt, giá trị TBD liên quan đã chốt.
- [ ] G02 effective shared store và cross-instance counters có evidence.
- [ ] G03 trusted proxies/client IP/origin controls có evidence chống spoof/bypass.
- [ ] G04 đã có prerequisites G02; outage, increment-false potential fail-open,
  burst/concurrency và counter-loss policy được duyệt/verify.
- [ ] G05 monitoring/alert delivery/on-call/runbook hoạt động và có owner.
- [ ] G06 representative-environment verification thực chạy đạt tại release SHA dự kiến,
  gồm guarded MySQL suite với REV-002 sau approval/isolation riêng; có post-test integrity.
- [ ] G08 có documented business/security risk decision: chấp nhận hoặc khắc phục với
  điều kiện release rõ ràng; không mặc định yêu cầu hoàn tất optional implementation.
- [ ] G07 rollout/stop/rollback và final production approval được ghi nhận.

Không tick một ô chỉ vì CI PASS, code merged, quyết định defer hoặc chưa quan sát lỗi.
Chỉ chuyển GO khi mọi P0 đạt category Implemented and verified **trong production
readiness scope**, có evidence, reviewer/owner và ngày xác nhận. P1 có thể chưa
implementation nếu được chấp thuận residual risk rõ ràng; không tự chuyển sang PASS.

Go/no-go record hiện tại:
- Decision: NO-GO.
- Release target: baseline main ở đầu tài liệu chỉ là assessment snapshot.
- Deployment environment: TBD.
- Decision owner / production approval / evidence bundle: TBD.
- Lần review tiếp theo: sau architecture decision hoặc thay đổi source/topology có liên quan.

## 15. References và quy tắc cập nhật evidence

Repository references:
- [AGENTS.md](../../AGENTS.md)
- [Architecture](../ai/architecture.md)
- [Database rules](../ai/database-rules.md)
- [Coding standards](../ai/coding-standards.md)
- [Feature development workflow](../ai/workflows/feature-development.md)
- [Testing workflow](../ai/workflows/testing-workflow.md)
- [Product API contract](../api/products.md)
- [MySQL testing harness/runbook](../testing/mysql-product-price.md)
- [Rate limiter provider](../../api/app/Providers/AppServiceProvider.php)
- [Rate-limit configuration](../../api/config/rate_limits.php)
- [Cache configuration](../../api/config/cache.php)
- [Middleware/proxy bootstrap](../../api/bootstrap/app.php)
- [Rate-limit Feature tests](../../api/tests/Feature/ProductRateLimitTest.php)
- [GitHub Actions workflow](../../.github/workflows/api-ci.yml)

PR/CI evidence links và verified SHAs nằm ở sections 2–3. Historical local counts
được giữ theo execution reports đã được báo cáo trong development workflow; reviewer
chưa kiểm chứng độc lập. Không tuyên bố tải/kiểm lại assertion counts từ GitHub job logs
trong lần documentation hoặc review fix này.

Khi cập nhật: giữ lịch sử theo scope/version, thêm evidence mới thay vì ghi đè kết
quả cũ; phân biệt local/CI/non-production/production; không đưa secrets vào evidence.
Mọi infrastructure/code/test execution và commit/push/PR/deploy vẫn cần approval
đúng scope. Tài liệu này không triển khai fixes và không đóng gate thay cho verification.
