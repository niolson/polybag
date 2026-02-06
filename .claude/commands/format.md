# .claude/commands/format.md
---
description: "Run format routine, fix issues, and ensure tests are green"
---

Run `composer run format`. If PHPStan reports issues, fix them and rerun until PHPStan passes.
Then run `composer run test`. If tests fail, fix them and rerun tests. After tests are green, rerun `composer run format`.