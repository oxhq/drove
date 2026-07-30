# Native DSL diagnostic — `2fb74fb4`

This is a reproducible diagnostic snapshot, not a broad performance claim.

- Drove revision: `2fb74fb404a331f61f3f9dcd32041b682c5b7fd4`
- Checkout: detached, exact revision, clean before and after the gate
- Platform: Linux x86_64, PHP 8.4.11, glibc 2.31
- Drover library SHA-256:
  `19d6194c84732175977371e28dbade2a81bccbb6964e5244dbb1f7cea0166197`
- Protocol: version 1, maximum frame size 1,048,576 bytes
- Resolved Composer lock SHA-256:
  `334f13ffbbb9e8510632f4661fc8142ab88115097f1c585eb70cf9125bd45255`
- Artifact-manifest SHA-256:
  `6d6452b8edc2198cd52bd3da0fc0f7b610d527d86b9cfb9857b8d39ffbea78e0`
- Verification: all conformance and stress comparisons passed; 12/12 stderr
  streams were empty

The conformance command was run once at each declared width:

```bash
DROVE_NATIVE_FIXTURE=conformance \
DROVE_NATIVE_SCHEDULER=drover \
DROVE_NATIVE_PROCESSES=C \
DROVE_EVIDENCE_REVISION=2fb74fb404a331f61f3f9dcd32041b682c5b7fd4 \
php -d ffi.enable=true experiments/native-phase-3/proof.php
```

The stress command was run twice:

```bash
DROVE_NATIVE_FIXTURE=stress \
DROVE_NATIVE_SCHEDULER=drover \
DROVE_NATIVE_PROCESSES=30 \
DROVE_EVIDENCE_REVISION=2fb74fb404a331f61f3f9dcd32041b682c5b7fd4 \
php -d ffi.enable=true -d memory_limit=512M \
  experiments/native-phase-3/proof.php
```

`Wall ms` is the kernel run duration. `Proof ms` includes the surrounding
scanner, CLI, planning, selection, and validation work. Parent and executor
peaks are PHP `memory_get_peak_usage(true)` samples; the executor value is the
maximum single-executor sample, not aggregate RSS.

| Fixture/run | Processes | Requested lanes | Observed lanes | Terminal/runnable | Assertions | Cleanups | Unique executor PIDs | Planning ms | Execution ms | Wall ms | Proof ms | Parent peak MiB | Executor peak MiB |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Conformance C1 | 1 | 1 | 1 | 49/47 | 46 | 35 | 47 | 13.872 | 3,573.282 | 3,553.559 | 6,476.213 | 4.000 | 4.000 |
| Conformance C2 | 2 | 2 | 2 | 49/47 | 46 | 35 | 47 | 14.404 | 1,569.757 | 1,549.527 | 3,951.199 | 4.000 | 4.000 |
| Conformance C4 | 4 | 4 | 4 | 49/47 | 46 | 35 | 47 | 13.998 | 828.500 | 808.649 | 3,178.303 | 4.000 | 4.000 |
| Conformance C8 | 8 | 8 | 8 | 49/47 | 46 | 35 | 47 | 14.503 | 479.367 | 458.113 | 2,827.460 | 4.000 | 4.000 |
| Conformance C16 | 16 | 16 | 16 | 49/47 | 46 | 35 | 47 | 13.892 | 298.801 | 279.033 | 2,628.415 | 4.000 | 4.000 |
| Conformance C30 | 30 | 30 | 30 | 49/47 | 46 | 35 | 47 | 14.527 | 220.174 | 199.823 | 2,573.952 | 4.000 | 4.000 |
| Stress C30 run 1 | 30 | 30 | 30 | 10,000/10,000 | 10,000 | 10,000 | 10,000 | 40.114 | 13,979.419 | 13,837.722 | 16,446.428 | 353.105 | 68.000 |
| Stress C30 run 2 | 30 | 30 | 30 | 10,000/10,000 | 10,000 | 10,000 | 10,000 | 45.688 | 14,159.771 | 14,012.134 | 16,603.874 | 353.246 | 68.000 |

Every conformance cell retained 45 passes, two expected failures, one skip,
one todo, the same IDs and semantic hashes, and one executor process per
runnable case. Both stress runs retained 10,000 passes with no batching.

Key artifact hashes:

| Artifact | SHA-256 |
|---|---|
| Conformance comparison + stress run 1 | `67c10f81f668d28f27f948a6264444b06eabbd06b7263225d82ef9afe8975599` |
| Conformance comparison + stress run 2 | `09eceae12851a8be6f5409037677afcf816f5e6e65360423b7d6c38fae6277c9` |
| Stress run 1 | `3724db9958deadc7fe5ae6bff1b12697d7f0b888f82b7c03f79f843a26512730` |
| Stress run 2 | `77ac38a39a02c5da87f722dd28f6f9ee0fb1185eee0a8cd7dab54152f7421734` |
| Drover ABI smoke | `5c1b07bd277fa33e4e59547c0bea52eb89d39119585ba75836a13c140ff32218` |

## Pest, PHPUnit, Laravel, and Testbench reference

The native fixture is synthetic and is not directly comparable to the
external compatibility corpus. The separate
[`d3dec629` hosted corpus snapshot](2026-07-29-d3dec629.md) records
processes, lanes, runs, tests, assertions, wall time, aggregate container
memory, and Drove PHP memory for Pest, PHPUnit, Laravel, and Testbench
frontends. Representative cells are reproduced here only to keep the
measurement boundary visible:

| Corpus | Frontend/runtime | Runner | Processes/lanes | Runs | Tests/assertions | Wall ms | Container peak MiB |
|---|---|---|---:|---:|---:|---:|---:|
| Pest | pest/plain | baseline | 1/1 | 1 | 785/1,690 | 1,378.459 | 76.1 |
| Pest | pest/plain | Drove | 1/1 | 1 | 785/1,690 | 11,918.942 | 370.7 |
| Pest | pest/plain | Drove | 8/8 | 1 | 785/1,690 | 5,342.674 | 405.0 |
| Livewire | phpunit/testbench | baseline | 1/1 | 1 | 288/1,034 | 3,496.846 | 102.4 |
| Livewire | phpunit/testbench | Drove | 1/1 | 1 | 288/1,034 | 25,031.996 | 127.7 |
| InvoiceShelf | pest/laravel | baseline | 1/1 | 1 | 202/328 | 28,014.980 | 120.2 |
| InvoiceShelf | pest/laravel | Drove | 8/8 | 1 | 202/328 | 65,968.700 | 541.5 |
| Filament | pest/testbench | baseline | 1/1 | 1 | 677/1,016 | 14,493.445 | 118.4 |
| Filament | pest/testbench | Drove | 8/8 | 1 | 677/1,016 | 10,014.988 | 544.5 |

Those external cells are single samples from revision `d3dec629`, not
Phase 3 native-DSL results. They remain evidence that Drove was slower and
used more aggregate memory than the reference runner in every process-matched
C1 cell. Repeated controlled bridge benchmarks remain pending.

The Phase 3 stress gate still needs a 512 MiB parent limit and 30 fixture
shards. Removing both crutches without weakening one-test-per-process
isolation is a Phase 5 gate.
