<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI BERT Microservice (Model Serving Service)
    |--------------------------------------------------------------------------
    | Kontrak ke service klasifikasi teks Python/FastAPI yang menyajikan
    | DUA model IndoBERT P2 fine-tuned:
    |
    |   1. model kategori  -> 12 label (Infrastruktur, Kebersihan, ...)
    |   2. model urgensi  -> 4 level  (Darurat, Tinggi, Sedang, Rendah)
    |
    | POST {url} dengan body { "text": "..." } membalas:
    | {
    |   "category": { "label_id": 0, "label": "Infrastruktur", "confidence": 0.93 },
    |   "urgency":  { "label_id": 2, "label": "Sedang",         "confidence": 0.81 },
    |   "processing_ms": 74
    | }
    |
    | CATATAN NFR-01: timeout dibatasi 3 detik. Kalau lewat timeout / service
    | down, laporan TETAP tersimpan dengan status 'pending' (FR-AI-03 kondisi 3).
    |
    | CATATAN THRESHOLD: nilai threshold di bawah SEHARUSNYA berasal dari
    | kurva kalibrasi (tools/evaluate.py di ai-service), bukan tebakan.
    | Lihat docs/06-ai-bert-dua-model.md.
    */
    'ai_bert' => [
        // Endpoint inferensi (POST) & health check (GET).
        'url' => env('AI_BERT_URL', 'http://127.0.0.1:8100/analyze'),
        'health_url' => env('AI_BERT_HEALTH_URL', 'http://127.0.0.1:8100/health'),

        // NFR-01: request ke AI maksimal 3 detik.
        'timeout' => (int) env('AI_BERT_TIMEOUT', 3),

        // Ambang confidence untuk auto-disposition kategori (FR-AI-03 kondisi 1).
        // Kalau confidence >= nilai ini, category_id langsung terisi hasil tebakan.
        'category_confidence_threshold' => (float) env('AI_BERT_CATEGORY_THRESHOLD', 0.85),

        // Ambang confidence untuk prioritas. Dipakai untuk badge level urgency.
        'priority_confidence_threshold' => (float) env('AI_BERT_PRIORITY_THRESHOLD', 0.70),
    ],

];
