# Repository Guidelines — RedEnlace PrestaShop Payment Module

## 1. Project Overview

This repository contains `redenlace`, a custom QR payment module for PrestaShop 1.7.8.2, intended for the Petsofia e-commerce store.

The module integrates with RedEnlace payment APIs. Its responsibilities include payment initiation, QR presentation, transaction tracking, payment confirmation, and PrestaShop order-state synchronization.

Do not assume undocumented RedEnlace endpoints, authentication mechanisms, request parameters, callback signatures, or payment statuses. Consult [`atcapiqrsimplev2.yaml`](atcapiqrsimplev2.yaml) before implementation; it is the repository's technical reference for the **ATC.API.QR.SIMPLE.V2** RedEnlace API.

Before changing QR creation, status checks, callbacks, authentication, request fields, or error handling, verify the applicable endpoint, schema, required fields, response codes, and security requirements in that YAML. Do not infer API behavior from UI requirements, and never hard-code credentials, tokens, merchant IDs, or callback URLs.

## 2. Compatibility Requirements

- Target PrestaShop 1.7.8.2.
- Support the PHP version configured on the target hosting server.
- Keep PHP syntax compatible with the verified runtime.
- Do not introduce PHP 8-only features while targeting PHP 7.x.
- Do not introduce PrestaShop 8 or 9-specific APIs.
- Follow the official PrestaShop 1.7 payment module API.
- Do not modify PrestaShop core files or unrelated modules.

## 3. Repository Structure

The module root is:

`modules/redenlace/`

- `redenlace.php`: module entry point, metadata, installation, configuration, and hook registration.
- `controllers/front/`: customer-facing payment, return, and callback controllers.
- `classes/`: API clients, payment services, validators, and persistence logic.
- `views/templates/`: Smarty templates for checkout and payment presentation.
- `views/css/` and `views/js/`: module-specific frontend assets.
- `index.php`: directory access protection.

Create additional directories only when required. Keep components focused and avoid unnecessary abstraction.

## 4. PrestaShop Integration

- The main module class must extend `PaymentModule`.
- Use `hookPaymentOptions()` to expose payment options.
- Implement `hookPaymentReturn()` when required by the payment flow.
- Use `PaymentOption` for the PrestaShop 1.7 checkout integration.
- Respect the native checkout confirmation flow.
- Use supported PrestaShop APIs for order creation and status changes.
- Validate cart ownership, customer context, amounts, and currencies.
- Do not bypass PrestaShop checkout validation.

## 5. Payment Architecture

Separate responsibilities between:

- Module initialization and configuration.
- RedEnlace API communication.
- Payment initialization and QR presentation.
- Payment-status verification.
- Callback and webhook processing.
- Transaction persistence.
- Order-state synchronization.

Do not place all payment logic inside `redenlace.php`.

Define transaction identifiers, persistence requirements, and status mappings before implementing state transitions.

## 6. Security Requirements

- Never hardcode API keys, secrets, or credentials.
- Never commit production credentials or sensitive configuration.
- Store secrets using appropriate server-side configuration mechanisms.
- Never expose provider credentials in JavaScript or Smarty templates.
- Use HTTPS and verify TLS certificates.
- Validate and authenticate incoming callbacks using the provider-supported mechanism.
- Validate payment amount, currency, merchant context, and transaction identifiers.
- Never mark an order as paid based solely on browser redirection.
- Prevent duplicate payments and repeated order-state transitions.
- Apply idempotency controls to payment initialization and callbacks.
- Do not log secrets, authorization headers, sensitive tokens, or complete private payloads.
- Validate input and escape output to prevent injection and XSS.

## 7. Payment State Management

Maintain a clear distinction between RedEnlace transaction statuses and PrestaShop order statuses.

At minimum, evaluate the following logical states:

- Pending
- Paid
- Failed
- Cancelled
- Expired

Map actual provider statuses only after verification against official API documentation.

Payment confirmation must be server-side and based on authenticated, verified provider information.

Handle duplicated, delayed, and out-of-order callbacks without corrupting an already finalized payment.

## 8. Development Workflow

Use the following deployment sequence:

Local VSCode → Git → PrestaShop Staging → Production.

- Develop and review code locally.
- Verify syntax before deployment.
- Test changes in an isolated PrestaShop environment.
- Use RedEnlace Sandbox credentials for integration testing.
- Deploy to production only after successful validation and authorization.
- Keep staging and production credentials separate.
- Never use production orders for uncontrolled testing.
- Do not modify production files directly during normal development.

When SSH is unavailable, prepare a distributable module ZIP or use an approved secure file-transfer mechanism.

## 9. Testing Requirements

Verify the following behaviors:

- Module installation and uninstallation.
- Module configuration.
- Payment method visibility.
- QR generation and display.
- Successful payment.
- Failed and cancelled payment.
- Expired or pending payment.
- Payment return without confirmation.
- Valid callback processing.
- Invalid callback rejection.
- Duplicate callback handling.
- Network timeout and API failure.
- Duplicate payment initiation.
- Incorrect payment amount or currency.
- Order-state synchronization.

Verify the complete checkout process in PrestaShop 1.7.8.2.

Add automated tests for isolated payment logic when practical.

## 10. Development Commands

Run PHP syntax validation on modified PHP files:

```sh
find modules/redenlace -type f -name '*.php' -exec php -l {} \;
```

Use the target PHP runtime version for compatibility checks.

Document additional tooling, dependencies, and test commands when introduced.

## 11. Coding Conventions

- Use four-space indentation.
- Follow the existing PHP brace conventions.
- Use descriptive camelCase for methods and variables.
- Use PascalCase for classes.
- Follow PrestaShop module naming conventions.
- Keep user-facing text translatable through supported PrestaShop mechanisms.
- Use `_PS_VERSION_` guards for relevant directly accessible PHP entry files.
- Keep controllers thin and delegate business logic to dedicated services.
- Prefer small, focused changes.
- Avoid unnecessary dependencies.

## 12. Git and GitHub Restrictions

Git and GitHub operations are exclusively managed by the repository owner.

The agent MUST NOT:

- Execute any Git commands, including `git add`, `git commit`, `git push`, `git pull`, `git fetch`, `git checkout`, `git switch`, `git merge`, or `git reset`.
- Create, modify, or delete branches, tags, commits, or pull requests.
- Perform GitHub operations through the CLI, APIs, extensions, or integrations.
- Automatically stage, commit, push, or synchronize changes.
- Modify repository history or Git configuration.

The agent MAY:

- Create, edit, and delete project source files when required by the development task.
- Execute local PHP syntax checks and tests.
- Review source code and report implemented changes.
- Suggest commit messages without executing Git commands.

All version control operations require manual execution by the repository owner.

**Never perform Git or GitHub operations, even when completing a development task.**

## 13. Agent Working Rules

Before modifying payment logic:

1. Inspect the relevant existing files.
2. Explain the proposed changes.
3. Identify compatibility and security implications.
4. Implement the smallest necessary change.
5. Run available validation commands.
6. Report which tests passed and which remain unverified.

Do not invent API contracts or assume provider behavior.

Do not perform destructive database operations, production deployments, or credential changes without explicit authorization.

When payment behavior is ambiguous, request clarification instead of implementing an unsafe assumption.
