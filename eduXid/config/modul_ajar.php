<?php

// Tidak ada API key yang ditanam dalam kode atau dikirim ke browser.
// offline: generator terstruktur dari ATP (tanpa LLM)
// ollama: LLM lokal di komputer (Ollama harus terpasang dan model diunduh)
// openai: LLM melalui API resmi (membutuhkan internet dan key milik operator)
return [
    'provider' => env('EDUXID_MODUL_AJAR_AI_PROVIDER', 'offline'),
    'openai_api_key' => env('EDUXID_MODUL_AJAR_OPENAI_API_KEY', ''),
    'openai_model' => env('EDUXID_MODUL_AJAR_OPENAI_MODEL', 'gpt-4.1-mini'),
    'ollama_model' => env('EDUXID_MODUL_AJAR_OLLAMA_MODEL', 'llama3.2'),
];
