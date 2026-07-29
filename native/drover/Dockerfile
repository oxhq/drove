FROM rust:1.88-bookworm@sha256:af306cfa71d987911a781c37b59d7d67d934f49684058f96cf72079c3626bfe0 AS native-builder

WORKDIR /build

COPY native/drover/Cargo.toml native/drover/Cargo.lock ./
COPY native/drover/src ./src
COPY native/drover/protocol ./protocol

RUN rustup component add clippy
RUN cargo test --locked
RUN cargo clippy --locked --all-targets -- -D warnings
RUN cargo build --release --locked

FROM php:8.4-cli@sha256:3a70cc45c4b03b691f04ea3c032030613a5ff6ee61053127aadb8a93b39cc7c3

RUN apt-get update \
    && apt-get install -y --no-install-recommends libffi-dev \
    && docker-php-ext-install ffi \
    && ldconfig \
    && rm -rf /var/lib/apt/lists/*

COPY --from=native-builder /build/target/release/libdrover.so /usr/local/lib/libdrover.so
COPY native/drover/proof.php /proof/proof.php
COPY native/drover/protocol /proof/protocol

CMD ["php", "-d", "ffi.enable=true", "/proof/proof.php"]
