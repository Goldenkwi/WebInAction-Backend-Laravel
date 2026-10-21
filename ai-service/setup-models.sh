#!/usr/bin/env bash
#
# Unduh 2 model IndoBERT P2 fine-tuned dari Google Drive.
#
# Total unduhan ~951 MB:
#   models/category/  -> model klasifikasi 12 label  (475 MB)
#   models/priority/  -> model urgensi 4 level        (475 MB)
#
# Cara pakai:
#   cd ai-service
#   ./setup-models.sh
#
# Butuh: gdown  ->  pip install gdown
#
set -euo pipefail

cd "$(dirname "$0")"

# --- ID folder Google Drive ---------------------------------------------
CATEGORY_FOLDER_ID="1eB8bWHMlay6nlHNE1PL8Dry28KhJ9wPF" # model_tupoksi_final_v2
PRIORITY_FOLDER_ID="1o51Wh2-qdDzqqThnOetPsetjqFfWlQZO" # model_urgency_final_v2

# --- Cek dependency ------------------------------------------------------
if ! command -v gdown >/dev/null 2>&1; then
    if [ -x ".venv/bin/gdown" ]; then
        echo "==> gdown tidak ada di PATH, pakai .venv/bin/gdown"
        GDOWN=".venv/bin/gdown"
    else
        echo "Error: gdown belum terinstall."
        echo "       pip install gdown"
        exit 1
    fi
else
    GDOWN="gdown"
fi

# --- Fungsi unduh --------------------------------------------------------
fetch() {
    local folder_id="$1"
    local target="$2"
    local label="$3"

    # Kalau sudah ada model.safetensors, skip.
    if [ -f "$target/model.safetensors" ]; then
        echo "==> [$label] sudah ada, dilewati."
        return 0
    fi

    echo "==> [$label] mengunduh dari Google Drive..."
    mkdir -p "$target"

    # Catatan: file >100 MB butuh "virus scan confirm" dari Google.
    # gdown sudah menanganinya otomatis (gdown biasa / curl biasa TIDAK).
    if ! "$GDOWN" --folder -O "$target" "$folder_id"; then
        echo "Error: gagal mengunduh [$label]."
        echo "       Coba manual:"
        echo "       $GDOWN --folder -O $target $folder_id"
        exit 1
    fi

    if [ ! -f "$target/model.safetensors" ]; then
        echo "Error: [$label] terunduh tapi model.safetensors tidak ditemukan."
        exit 1
    fi

    echo "==> [$label] selesai ($(du -sh "$target" | cut -f1))"
}

fetch "$CATEGORY_FOLDER_ID" "models/category" "model kategori (12 label)"
fetch "$PRIORITY_FOLDER_ID" "models/priority" "model prioritas (4 level)"

echo
echo "Model siap. Cek kontrak label dengan database:"
echo "    cd .. && php artisan ai:check-labels"
