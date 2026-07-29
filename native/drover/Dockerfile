FROM rust:1.88-bullseye@sha256:b315f988b86912bafa7afd39a6ded0a497bf850ec36578ca9a3bdd6a14d5db4e AS native-builder

WORKDIR /build

COPY native/drover/Cargo.toml native/drover/Cargo.lock ./
COPY native/drover/src ./src
COPY native/drover/protocol ./protocol

RUN rustup component add clippy rustfmt
RUN cargo fmt --check
RUN cargo test --locked
RUN cargo clippy --locked --all-targets -- -D warnings
RUN cargo build --release --locked

FROM php:8.4-cli-bullseye@sha256:aa9ca431aa35219492184f73202359109e172e33710e7d9928ee7da600b60cb7

RUN apt-get update \
    && apt-get install -y --no-install-recommends libffi-dev \
    && docker-php-ext-install ffi \
    && test "$(getconf GNU_LIBC_VERSION)" = "glibc 2.31" \
    && ldconfig \
    && rm -rf /var/lib/apt/lists/*

COPY --from=native-builder /build/target/release/libdrover.so /usr/local/lib/libdrover.so
COPY native/drover/proof.php /proof/proof.php
COPY native/drover/protocol /proof/protocol

CMD ["php", "-d", "ffi.enable=true", "/proof/proof.php"]
