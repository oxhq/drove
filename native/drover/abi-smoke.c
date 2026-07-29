#include <stdint.h>
#include <stddef.h>
#include <stdio.h>

extern uint32_t drover_protocol_version(void);
extern size_t drover_protocol_max_frame_bytes(void);

int main(void)
{
    if (drover_protocol_version() != 1) {
        fputs("Drover protocol version mismatch.\n", stderr);

        return 1;
    }

    if (drover_protocol_max_frame_bytes() != 1048576) {
        fputs("Drover protocol frame limit mismatch.\n", stderr);

        return 1;
    }

    puts("Drover native ABI v1 passed.");

    return 0;
}
