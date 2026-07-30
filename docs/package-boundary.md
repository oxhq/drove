# Package boundary

The alpha ships one Composer archive with a native default and an explicit
compatibility opt-in:

| Surface | Entrypoint | Role |
| --- | --- | --- |
| Native Drove | `vendor/bin/drove` | Product API: native declarations, Scope IR, lifecycle kernel, and Drover scheduling. `--native` remains a temporary alias. |
| Pest compatibility | `vendor/bin/drove --pest` and `vendor/bin/pest` | Explicit bridge-only migration surface for the pinned Pest 5 corpus. |
| PHPUnit/Testbench compatibility | Explicit bridge entrypoints | Bridge-only suite lowering into the same Scope IR. |

`bin/pest`, the `Pest\` namespace, the global Pest function files,
`pestphp/pest-plugin`, and the `pestphp/pest` replacement are classified under
`extra.drove.bridge-only` in `composer.json`. They are not native Drove APIs.
The namespace and global files live in development autoload only. The explicit
bridge bootstrap loads them after verifying that its suggested dependencies
were deliberately installed. The replacement remains necessary so opted-in
Pest 5 plugins can resolve against this single alpha package.

The `pestphp/pest` replacement is the remaining physical coupling: it prevents
installing real Pest beside Drove even though it does not load bridge code.
That migration debt is explicit for this alpha. A separate bridge package and
removal of the replacement are required before a stable release.

Architecture tests, mutation testing, and profanity mode are unsupported Drove
surfaces. Their inherited packages are development-only so a consumer install
does not download them. They remain in `require-dev` only to test and maintain
the derived Pest source while it still lives in this repository. A no-dev
consumer receives the registry's stable unsupported diagnostic from `arch()`
or `mutates()` before either surface can declare a test body.

The production dependency graph does not require or autoload Pest, PHPUnit,
ParaTest, Collision, Termwind, Symfony Process, or the Pest Composer plugin.
They remain `require-dev` dependencies for this repository and `suggest`
entries for consumers that deliberately run `vendor/bin/drove --pest` or
`vendor/bin/pest`. A missing bridge dependency fails with
`DROVE_PEST_BRIDGE_MISSING_DEPENDENCIES`; Drove never falls back silently.

The source repository gates this metadata before building the Composer archive:

```bash
php scripts/verify-package-boundary.php
```

The verification script is development tooling and is not part of the
published package.
