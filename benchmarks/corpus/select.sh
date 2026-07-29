#!/bin/sh
set -eu

target=${1:-}
root=${2:-.}

if [ ! -d "$root" ]; then
    echo "corpus root does not exist: $root" >&2
    exit 2
fi

cd "$root"

case "$target" in
    pest)
        # Pest is the inherited source surface in this fork, not an external checkout.
        commit=
        expected_files=143
        required_directories="tests/Unit tests/Features"
        ;;
    invoiceshelf)
        commit=403a4d67225a153838ec126c484339abf60229d1
        expected_files=47
        required_directories="tests/Unit tests/Feature/Customer"
        ;;
    livewire)
        commit=9c1450739d30c9b0b223ad6512be2a33f8f62f96
        expected_files=20
        required_directories="src"
        ;;
    filament)
        commit=e9348b2e3792088ee877068116b6c1e1559a7df8
        expected_files=39
        required_directories="tests/src/Support"
        ;;
    *)
        echo "usage: $0 pest|invoiceshelf|livewire|filament [corpus-root]" >&2
        exit 2
        ;;
esac

if [ -n "$commit" ]; then
    if ! command -v git >/dev/null 2>&1; then
        echo "git is required to verify an external corpus checkout" >&2
        exit 2
    fi

    actual_commit=$(git rev-parse HEAD 2>/dev/null || true)

    if [ "$actual_commit" != "$commit" ]; then
        echo "corpus commit mismatch: expected $commit, found ${actual_commit:-none}" >&2
        exit 2
    fi

    if [ -n "$(git status --porcelain -- $required_directories)" ]; then
        echo "corpus selection roots contain uncommitted changes" >&2
        exit 2
    fi
fi

for directory in $required_directories; do
    if [ ! -d "$directory" ]; then
        echo "corpus directory does not exist: $directory" >&2
        exit 2
    fi
done

selection=$(mktemp)
trap 'rm -f "$selection"' EXIT HUP INT TERM

case "$target" in
    pest)
        find tests/Unit tests/Features -type f -name '*.php' -print |
            LC_ALL=C sort |
            while IFS= read -r path; do
                case "$path" in
                    tests/Unit/Preset.php|\
                    tests/Unit/TestName.php|\
                    tests/Unit/Support/Container.php|\
                    tests/Features/After.php|\
                    tests/Features/AfterEach.php|\
                    tests/Features/BeforeAll.php|\
                    tests/Features/BeforeEach.php|\
                    tests/Features/BeforeEachProxiesToTestCallWithTodo.php|\
                    tests/Features/Coverage.php|\
                    tests/Features/DatasetMethodChaining.php|\
                    tests/Features/DatasetsTests.php|\
                    tests/Features/Depends.php|\
                    tests/Features/DependsInheritance.php|\
                    tests/Features/Deprecated.php|\
                    tests/Features/Describe.php|\
                    tests/Features/DescriptionLess.php|\
                    tests/Features/Flaky.php|\
                    tests/Features/Helpers.php|\
                    tests/Features/HigherOrderTests.php|\
                    tests/Features/Incompleted.php|\
                    tests/Features/It.php|\
                    tests/Features/Notices.php|\
                    tests/Features/Repeat.php|\
                    tests/Features/Skip.php|\
                    tests/Features/SkipOnPhp.php|\
                    tests/Features/Test.php|\
                    tests/Features/Todo.php|\
                    tests/Features/Warnings.php|\
                    tests/Features/Expect/HigherOrder/methods.php|\
                    tests/Features/Expect/HigherOrder/methodsAndProperties.php|\
                    tests/Features/Expect/HigherOrder/properties.php|\
                    tests/Features/Expect/matchExpectation.php|\
                    tests/Features/Expect/toBeCasedCorrectly.php|\
                    tests/Features/Expect/toBeIntBackedEnum.php|\
                    tests/Features/Expect/toBeInvokable.php|\
                    tests/Features/Expect/toBeStringBackedEnum.php|\
                    tests/Features/Expect/toHaveAttribute.php|\
                    tests/Features/Expect/toHaveConstructor.php|\
                    tests/Features/Expect/toHaveDestructor.php|\
                    tests/Features/Expect/toHaveKey.php|\
                    tests/Features/Expect/toHaveMethod.php|\
                    tests/Features/Expect/toHaveMethods.php|\
                    tests/Features/Expect/toHavePrefix.php|\
                    tests/Features/Expect/toHaveSuffix.php|\
                    tests/Features/Expect/toMatchSnapshot.php|\
                    tests/Features/Expect/toUseStrictEquality.php|\
                    tests/Features/Expect/unless.php|\
                    tests/Features/Expect/when.php|\
                    tests/Features/ScopedDatasets/TestFileOutOfScope.php|\
                    tests/Features/ScopedDatasets/Directory/TestFileWithScopedDataset.php|\
                    tests/Features/ScopedDatasets/Directory/TestFileWithLocallyDefinedDataset.php|\
                    tests/Features/ScopedDatasets/Directory/NestedDirectory1/TestFileInNestedDirectoryWithDatasetsFile.php|\
                    tests/Features/ScopedDatasets/Directory/NestedDirectory2/TestFileInNestedDirectory.php)
                        continue
                        ;;
                esac

                if grep -Eq '(^|[^[:alnum:]_])(test|it|describe)[[:space:]]*\(' "$path"; then
                    printf '%s\n' "$path"
                fi
            done > "$selection"
        ;;
    invoiceshelf)
        find tests/Unit tests/Feature/Customer -type f -name '*.php' -print |
            LC_ALL=C sort > "$selection"
        ;;
    livewire)
        find src -type f -name '*UnitTest.php' -print |
            LC_ALL=C sort |
            sed -n '1,20p' > "$selection"
        ;;
    filament)
        find tests/src/Support -type f -name '*.php' -print |
            LC_ALL=C sort > "$selection"
        ;;
esac

actual_files=$(wc -l < "$selection" | tr -d '[:space:]')

if [ "$actual_files" != "$expected_files" ]; then
    echo "corpus selection mismatch: expected $expected_files files, found $actual_files" >&2
    exit 2
fi

cat "$selection"
