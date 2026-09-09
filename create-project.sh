#!/usr/bin/env bash
#
# Creates a new ComfreePHP project from this checkout:
#
#   bash create-project.sh ../my-app                 # interactive install
#   bash create-project.sh ../my-app --no-interaction --db=sqlite --migrate
#
# Copies the framework skeleton (the files git knows about when this is a git
# checkout, otherwise a plain copy without .git and runtime files) into the
# target directory and runs install.php there. Extra arguments are passed
# through to install.php.

set -euo pipefail

if [ $# -lt 1 ]; then
    echo "Usage: bash create-project.sh <target-directory> [install.php options]" >&2
    exit 1
fi

source_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
target="$1"
shift

if [ -e "$target" ] && [ -n "$(ls -A "$target" 2>/dev/null)" ]; then
    echo "Target directory $target exists and is not empty." >&2
    exit 1
fi

mkdir -p "$target"

if git -C "$source_dir" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    # Tracked plus new-but-not-ignored files, so uncommitted work is included
    # while .env, storage output and caches (git-ignored) are not.
    echo "Exporting files known to git..."
    git -C "$source_dir" ls-files -z --cached --others --exclude-standard \
        | (cd "$source_dir" && tar --null -T - -cf -) | tar -x -C "$target"
else
    echo "Copying files..."
    # No git: copy everything except the VCS directory and runtime output.
    (cd "$source_dir" && tar -c \
        --exclude='./.git' \
        --exclude='./.env' \
        --exclude='./storage/logs/*' \
        --exclude='./storage/cache/*' \
        --exclude='./storage/views/*' \
        --exclude='./storage/*.sqlite' \
        --exclude='./bootstrap/cache/*' \
        .) | tar -x -C "$target"
fi

# Runtime directories are git-ignored, so make sure they exist.
mkdir -p "$target/storage/logs" "$target/storage/cache" "$target/storage/views" "$target/bootstrap/cache"

# The scaffolded app starts as its own project.
rm -rf "$target/.git" "$target/create-project.sh"

echo "Skeleton copied to $target"
echo

cd "$target"
php install.php "$@"
