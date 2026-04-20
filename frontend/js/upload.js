/**
 * upload.js — File converter Alpine.js component
 */

// Format groups mapping: source format → available target formats
const FORMAT_GROUPS = {
  // Documents
  doc:   ['docx','odt','pdf','txt','html','md','rtf','epub'],
  docx:  ['odt','pdf','txt','html','md','rtf','epub'],
  odt:   ['docx','pdf','txt','html','md','rtf','epub'],
  rtf:   ['docx','odt','pdf','txt','html','md'],
  txt:   ['docx','odt','pdf','html','md'],
  html:  ['docx','odt','pdf','txt','md'],
  epub:  ['docx','odt','pdf','txt','html'],
  // PDF
  pdf:   ['docx','txt','md','jpg'],
  // Markup
  md:    ['rst','html','pdf','docx'],
  rst:   ['md','html','pdf','docx'],
  // Data
  csv:   ['json','xml','yaml'],
  json:  ['csv','xml','yaml'],
  xml:   ['csv','json','yaml'],
  yaml:  ['csv','json','xml'],
  // Images
  jpg:   ['png','gif','bmp','webp','tiff','ico','avif','pdf'],
  jpeg:  ['png','gif','bmp','webp','tiff','ico','avif','pdf'],
  png:   ['jpg','gif','bmp','webp','tiff','ico','avif','pdf'],
  gif:   ['jpg','png','bmp','webp','tiff'],
  bmp:   ['jpg','png','gif','webp','tiff'],
  webp:  ['jpg','png','gif','bmp','tiff'],
  tiff:  ['jpg','png','gif','bmp','webp'],
  svg:   ['png','jpg','pdf'],
  ico:   ['png','jpg'],
  avif:  ['jpg','png','webp'],
  heic:  ['jpg','png','webp'],
  // Audio
  mp3:   ['wav','ogg','flac','aac','m4a','opus'],
  wav:   ['mp3','ogg','flac','aac','m4a','opus'],
  ogg:   ['mp3','wav','flac','aac','m4a','opus'],
  flac:  ['mp3','wav','ogg','aac','m4a'],
  aac:   ['mp3','wav','ogg','flac','m4a'],
  m4a:   ['mp3','wav','ogg','flac','aac'],
  opus:  ['mp3','wav','ogg','flac'],
  wma:   ['mp3','wav','ogg','flac'],
  // Video
  mp4:   ['avi','mkv','mov','webm','mp3','wav','ogg','flac'],
  avi:   ['mp4','mkv','mov','webm','mp3','wav'],
  mkv:   ['mp4','avi','mov','webm','mp3','wav'],
  mov:   ['mp4','avi','mkv','webm','mp3','wav'],
  webm:  ['mp4','avi','mkv','mov'],
  flv:   ['mp4','avi','mkv'],
  wmv:   ['mp4','avi','mkv'],
  // Archives
  zip:   ['tar.gz'],
  tar:   ['zip'],
  gz:    ['zip'],
  '7z':  ['zip','tar.gz'],
  // Spreadsheets
  xls:   ['xlsx','ods','csv','pdf'],
  xlsx:  ['ods','csv','pdf'],
  ods:   ['xlsx','csv','pdf'],
  // Presentations
  ppt:   ['pptx','odp','pdf'],
  pptx:  ['odp','pdf'],
  odp:   ['pptx','pdf'],
  // CAD
  dwg:   ['pdf','svg','png'],
  dxf:   ['pdf','svg','png'],
};

function converter() {
  return {
    // State
    file: null,
    fromFormat: '',
    toFormat: '',
    status: 'idle', // 'idle'|'uploading'|'pending'|'processing'|'done'|'error'
    conversionId: null,
    resultUrl: null,
    error: '',
    progress: 0,
    dragOver: false,
    availableFormats: {},
    pollTimer: null,

    // Computed
    get toFormats() {
      if (!this.fromFormat) return [];
      // Try local map first, then loaded from API
      return FORMAT_GROUPS[this.fromFormat.toLowerCase()]
        || this.availableFormats[this.fromFormat.toLowerCase()]
        || [];
    },

    get canConvert() {
      return this.file && this.fromFormat && this.toFormat && this.status === 'idle';
    },

    get isLoading() {
      return ['uploading', 'pending', 'processing'].includes(this.status);
    },

    get statusInfo() {
      return formatStatus(this.status);
    },

    // Initialize: load formats from API
    async init() {
      try {
        const response = await fetch('/api/v1/formats');
        if (response.ok) {
          const data = await response.json();
          this.availableFormats = data.formats || {};
        }
      } catch (e) {
        // Use built-in FORMAT_GROUPS as fallback
      }
    },

    // Drag & drop handlers
    onDragOver(e) {
      e.preventDefault();
      this.dragOver = true;
    },

    onDragLeave(e) {
      this.dragOver = false;
    },

    onDrop(e) {
      e.preventDefault();
      this.dragOver = false;
      const files = e.dataTransfer?.files;
      if (files?.length) this.selectFile(files[0]);
    },

    onFileInput(e) {
      const files = e.target?.files;
      if (files?.length) this.selectFile(files[0]);
    },

    // Select and analyze file
    selectFile(file) {
      this.file = file;
      this.status = 'idle';
      this.error = '';
      this.resultUrl = null;
      this.conversionId = null;

      // Auto-detect format from extension
      const ext = file.name.split('.').pop()?.toLowerCase() || '';
      this.fromFormat = ext;
      this.toFormat = '';

      // Auto-select first available target format
      const targets = this.toFormats;
      if (targets.length) this.toFormat = targets[0];
    },

    // Submit conversion job
    async submitConversion() {
      if (!this.canConvert) return;

      this.status = 'uploading';
      this.error = '';
      this.progress = 0;

      try {
        const formData = new FormData();
        formData.append('file', this.file);
        formData.append('from_format', this.fromFormat);
        formData.append('to_format', this.toFormat);

        const response = await apiFetch('/api/v1/convert', {
          method: 'POST',
          body: formData,
        });

        if (!response.ok) {
          const err = await response.json().catch(() => ({}));
          throw new Error(err.message || `Ошибка ${response.status}`);
        }

        const data = await response.json();
        this.conversionId = data.id;
        this.status = 'pending';
        this.startPolling(data.id);

      } catch (err) {
        this.status = 'error';
        this.error = err.message || 'Ошибка загрузки файла';
      }
    },

    // Start HTMX-style polling via JS
    startPolling(id) {
      this.stopPolling();
      this.pollTimer = setInterval(() => this.pollStatus(id), 2000);
    },

    stopPolling() {
      if (this.pollTimer) {
        clearInterval(this.pollTimer);
        this.pollTimer = null;
      }
    },

    // Poll conversion status
    async pollStatus(id) {
      try {
        const response = await apiFetch(`/api/v1/convert/${id}/status`);
        if (!response.ok) throw new Error(`Status ${response.status}`);

        const data = await response.json();
        this.status = data.status;

        if (data.status === 'done') {
          this.resultUrl = `/api/v1/convert/${id}/download`;
          this.stopPolling();
        } else if (data.status === 'error') {
          this.error = data.error || 'Ошибка конвертации';
          this.stopPolling();
        }
      } catch (err) {
        // Don't stop polling on network errors, keep trying
        console.error('Poll error:', err);
      }
    },

    // Trigger file download
    downloadResult(id) {
      const link = document.createElement('a');
      link.href = this.resultUrl || `/api/v1/convert/${id}/download`;
      link.download = `converted_${this.file?.name?.replace(/\.[^.]+$/, '')}.${this.toFormat}`;
      link.click();
    },

    // Reset to initial state
    reset() {
      this.stopPolling();
      this.file = null;
      this.fromFormat = '';
      this.toFormat = '';
      this.status = 'idle';
      this.conversionId = null;
      this.resultUrl = null;
      this.error = '';
      this.progress = 0;
      // Reset file input
      const input = document.getElementById('file-input');
      if (input) input.value = '';
    },
  };
}
