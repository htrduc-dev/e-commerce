
# E-Commerce Architecture

## Technology
- PHP: ^8.3
- Framework: Laravel 13
- ORM: Eloquent
- Repository: Git monorepo

## Applications
- api: REST API and backend operations
- web-admin: Administration
- web-seller: Seller management
- web-buyer: Buyer application
- miniapp-buyer: Planned application

## Database Architecture
All applications use one shared business database.

Database engine:
- MySQL 8.0.

## Schema Ownership
- Shared business migrations belong to api/database/migrations.
- Other applications must not create shared business migrations.
- Schema changes require developer approval.

## Application Boundaries
- Each application has its own Laravel bootstrap.
- Each application maintains its own Eloquent models.
- Shared database access is allowed.
- Cross-application changes require impact analysis.

## Business Rules
- Important business operations must remain consistent.
- Transactions must be used where atomicity is required.
- Inventory, orders and payments require special review.
