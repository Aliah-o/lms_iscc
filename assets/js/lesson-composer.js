(function() {
    const modal = document.getElementById('createLessonModal');
    if (!modal) return;

    const config = window.lessonComposerConfig || {};
    const maxFiles = Number(config.maxFiles || 5);
    const maxFileSize = Number(config.maxFileSize || (10 * 1024 * 1024));
    const draftKey = config.draftKey || 'lesson-draft-default';

    const form = document.getElementById('createLessonForm');
    const hiddenContent = document.getElementById('lessonContentInput');
    const clientAttachmentCountInput = document.getElementById('lessonClientAttachmentCount');
    const clientAttachmentNamesInput = document.getElementById('lessonClientAttachmentNames');
    const titleInput = document.getElementById('lessonTitleInput');
    const videoInput = document.getElementById('lessonVideoInput');
    const linkTitleInput = document.getElementById('lessonLinkTitleInput');
    const linkUrlInput = document.getElementById('lessonLinkUrlInput');
    const richEditor = document.getElementById('lessonRichEditor');
    const markdownEditor = document.getElementById('lessonMarkdownEditor');
    const previewPane = document.getElementById('lessonPreviewPane');
    const previewContent = previewPane.querySelector('.lesson-preview-content');
    const draftState = document.getElementById('lessonDraftState');
    const toolbar = document.getElementById('lessonEditorToolbar');
    const modeButtons = Array.from(document.querySelectorAll('.lesson-mode-btn'));
    const imageInput = document.getElementById('lessonEditorImageInput');
    const attachmentInput = document.getElementById('lessonAttachmentInput');
    const attachmentPicker = document.getElementById('lessonAttachmentPicker');
    const attachmentDropzone = document.getElementById('lessonAttachmentDropzone');
    const attachmentPreviewList = document.getElementById('lessonAttachmentPreviewList');

    let currentMode = 'rich';
    let lastEditableMode = 'rich';
    let savedSelection = null;
    let attachmentFiles = [];

    function debounce(fn, delay) {
        let timer = null;
        return function() {
            const args = arguments;
            clearTimeout(timer);
            timer = window.setTimeout(() => fn.apply(null, args), delay || 300);
        };
    }

    function toast(message, type) {
        if (typeof window.showToast === 'function') {
            window.showToast(message, type || 'info');
        }
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function renderInlineMarkdown(text) {
        return escapeHtml(text)
            .replace(/!\[([^\]]*)\]\(([^)]+)\)/g, '<figure class="lesson-inline-figure"><img src="$2" alt="$1"></figure>')
            .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>')
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/\*([^*]+)\*/g, '<em>$1</em>')
            .replace(/`([^`]+)`/g, '<code>$1</code>');
    }

    function htmlToMarkdown(html) {
        const root = document.createElement('div');
        root.innerHTML = html || '';

        function walk(node) {
            if (node.nodeType === Node.TEXT_NODE) {
                return node.textContent;
            }
            if (node.nodeType !== Node.ELEMENT_NODE) {
                return '';
            }

            const tag = node.tagName.toLowerCase();
            const children = Array.from(node.childNodes).map(walk).join('');

            if (tag === 'strong' || tag === 'b') return '**' + children + '**';
            if (tag === 'em' || tag === 'i') return '*' + children + '*';
            if (tag === 'code' && (!node.parentElement || node.parentElement.tagName.toLowerCase() !== 'pre')) return '`' + node.textContent + '`';
            if (tag === 'a') return '[' + (children || node.textContent || node.href) + '](' + (node.getAttribute('href') || '') + ')';
            if (tag === 'h1') return '# ' + node.textContent.trim() + '\n\n';
            if (tag === 'h2') return '## ' + node.textContent.trim() + '\n\n';
            if (tag === 'h3') return '### ' + node.textContent.trim() + '\n\n';
            if (tag === 'blockquote') return node.textContent.trim().split('\n').map(line => '> ' + line.trim()).join('\n') + '\n\n';
            if (tag === 'hr') return '---\n\n';
            if (tag === 'pre') return '```\n' + node.textContent.replace(/\n+$/, '') + '\n```\n\n';
            if (tag === 'ul') return Array.from(node.children).map(li => '- ' + li.textContent.trim()).join('\n') + '\n\n';
            if (tag === 'ol') return Array.from(node.children).map((li, index) => (index + 1) + '. ' + li.textContent.trim()).join('\n') + '\n\n';
            if (tag === 'img') return '![' + (node.getAttribute('alt') || 'Image') + '](' + (node.getAttribute('src') || '') + ')\n\n';
            if (tag === 'table') {
                const rows = Array.from(node.querySelectorAll('tr')).map(tr => Array.from(tr.children).map(cell => cell.textContent.trim()));
                if (!rows.length) return '';
                const header = '| ' + rows[0].join(' | ') + ' |';
                const divider = '| ' + rows[0].map(() => '---').join(' | ') + ' |';
                const body = rows.slice(1).map(row => '| ' + row.join(' | ') + ' |').join('\n');
                return header + '\n' + divider + (body ? '\n' + body : '') + '\n\n';
            }
            if (tag === 'p' || tag === 'div') {
                const trimmed = children.trim();
                return trimmed ? trimmed + '\n\n' : '';
            }
            if (tag === 'br') return '\n';

            return children;
        }

        return Array.from(root.childNodes).map(walk).join('').replace(/\n{3,}/g, '\n\n').trim();
    }

    function markdownToHtml(markdown) {
        const lines = String(markdown || '').replace(/\r\n/g, '\n').split('\n');
        const blocks = [];
        let i = 0;

        function collectParagraph(startIndex) {
            const parts = [];
            let cursor = startIndex;
            while (cursor < lines.length && lines[cursor].trim() !== '') {
                const test = lines[cursor].trim();
                if (/^#{1,3}\s/.test(test) || /^>\s?/.test(test) || /^```/.test(test) || /^(-|\*|\d+\.)\s/.test(test) || /^\|/.test(test) || /^---+$/.test(test) || /^\*\*\*+$/.test(test)) {
                    break;
                }
                parts.push(lines[cursor]);
                cursor++;
            }
            return {
                html: '<p>' + renderInlineMarkdown(parts.join('\n')).replace(/\n/g, '<br>') + '</p>',
                nextIndex: cursor
            };
        }

        while (i < lines.length) {
            const line = lines[i];
            const trimmed = line.trim();

            if (!trimmed) {
                i++;
                continue;
            }

            if (/^```/.test(trimmed)) {
                const codeLines = [];
                i++;
                while (i < lines.length && !/^```/.test(lines[i].trim())) {
                    codeLines.push(lines[i]);
                    i++;
                }
                blocks.push('<pre class="lesson-editor-code"><code>' + escapeHtml(codeLines.join('\n')) + '</code></pre>');
                i++;
                continue;
            }

            if (/^#{1,3}\s/.test(trimmed)) {
                const level = trimmed.match(/^#+/)[0].length;
                blocks.push('<h' + level + '>' + renderInlineMarkdown(trimmed.replace(/^#{1,3}\s*/, '')) + '</h' + level + '>');
                i++;
                continue;
            }

            if (/^>\s?/.test(trimmed)) {
                const quoteLines = [];
                while (i < lines.length && /^>\s?/.test(lines[i].trim())) {
                    quoteLines.push(lines[i].trim().replace(/^>\s?/, ''));
                    i++;
                }
                blocks.push('<blockquote class="lesson-editor-quote">' + renderInlineMarkdown(quoteLines.join('\n')).replace(/\n/g, '<br>') + '</blockquote>');
                continue;
            }

            if (/^---+$/.test(trimmed) || /^\*\*\*+$/.test(trimmed)) {
                blocks.push('<hr class="lesson-editor-divider">');
                i++;
                continue;
            }

            if (/^\|/.test(trimmed) && i + 1 < lines.length && /^\|\s*[-:| ]+\|?$/.test(lines[i + 1].trim())) {
                const headerCells = trimmed.split('|').filter(Boolean).map(cell => cell.trim());
                const rows = [];
                i += 2;
                while (i < lines.length && /^\|/.test(lines[i].trim())) {
                    rows.push(lines[i].trim().split('|').filter(Boolean).map(cell => cell.trim()));
                    i++;
                }
                const thead = '<thead><tr>' + headerCells.map(cell => '<th>' + renderInlineMarkdown(cell) + '</th>').join('') + '</tr></thead>';
                const tbody = rows.length ? '<tbody>' + rows.map(row => '<tr>' + row.map(cell => '<td>' + renderInlineMarkdown(cell) + '</td>').join('') + '</tr>').join('') + '</tbody>' : '';
                blocks.push('<div class="lesson-table-wrap"><table class="lesson-editor-table">' + thead + tbody + '</table></div>');
                continue;
            }

            if (/^(-|\*)\s+/.test(trimmed)) {
                const items = [];
                while (i < lines.length && /^(-|\*)\s+/.test(lines[i].trim())) {
                    items.push(lines[i].trim().replace(/^(-|\*)\s+/, ''));
                    i++;
                }
                blocks.push('<ul>' + items.map(item => '<li>' + renderInlineMarkdown(item) + '</li>').join('') + '</ul>');
                continue;
            }

            if (/^\d+\.\s+/.test(trimmed)) {
                const items = [];
                while (i < lines.length && /^\d+\.\s+/.test(lines[i].trim())) {
                    items.push(lines[i].trim().replace(/^\d+\.\s+/, ''));
                    i++;
                }
                blocks.push('<ol>' + items.map(item => '<li>' + renderInlineMarkdown(item) + '</li>').join('') + '</ol>');
                continue;
            }

            const paragraph = collectParagraph(i);
            blocks.push(paragraph.html);
            i = paragraph.nextIndex;
        }

        return blocks.join('');
    }

    function updatePlaceholder() {
        const html = richEditor.innerHTML.replace(/<br\s*\/?>/gi, '').replace(/&nbsp;/gi, '').trim();
        const isEmpty = richEditor.textContent.trim() === '' && html === '';
        richEditor.setAttribute('data-empty', isEmpty ? 'true' : 'false');
    }

    function setDraftMessage(text) {
        draftState.innerHTML = '<i class="bi bi-cloud-check me-2"></i>' + text;
    }

    function autoGrowMarkdown() {
        markdownEditor.style.height = 'auto';
        markdownEditor.style.height = Math.max(markdownEditor.scrollHeight, 380) + 'px';
    }

    function buildPreview() {
        const html = lastEditableMode === 'markdown' ? markdownToHtml(markdownEditor.value) : richEditor.innerHTML;
        if (html.trim()) {
            previewContent.innerHTML = html;
            return;
        }

        previewContent.innerHTML = [
            '<div class="lesson-preview-empty">',
            '<i class="bi bi-stars"></i>',
            '<strong>Preview is empty</strong>',
            '<span>Start writing to see your lesson here.</span>',
            '</div>'
        ].join('');
    }

    function syncHiddenContent() {
        hiddenContent.value = lastEditableMode === 'markdown'
            ? markdownToHtml(markdownEditor.value).trim()
            : richEditor.innerHTML.trim();
    }

    function refreshModeUi() {
        modeButtons.forEach(button => {
            button.classList.toggle('active', button.dataset.editorMode === currentMode);
        });
        richEditor.classList.toggle('d-none', currentMode !== 'rich');
        markdownEditor.classList.toggle('d-none', currentMode !== 'markdown');
        previewPane.classList.toggle('d-none', currentMode !== 'preview');
        toolbar.classList.toggle('is-disabled', currentMode === 'preview');
    }

    function setMode(mode) {
        if (mode === currentMode) return;

        if (mode === 'markdown') {
            if (lastEditableMode !== 'markdown') {
                markdownEditor.value = htmlToMarkdown(richEditor.innerHTML);
            }
            autoGrowMarkdown();
            lastEditableMode = 'markdown';
        } else if (mode === 'rich') {
            if (lastEditableMode === 'markdown') {
                richEditor.innerHTML = markdownToHtml(markdownEditor.value);
            }
            lastEditableMode = 'rich';
            updatePlaceholder();
        } else if (mode === 'preview') {
            buildPreview();
        }

        currentMode = mode;
        refreshModeUi();

        if (mode === 'rich') {
            window.setTimeout(() => richEditor.focus(), 60);
        } else if (mode === 'markdown') {
            window.setTimeout(() => markdownEditor.focus(), 60);
        }
    }

    function saveSelection() {
        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) return;
        const range = selection.getRangeAt(0);
        if (richEditor.contains(range.commonAncestorContainer)) {
            savedSelection = range.cloneRange();
        }
    }

    function restoreSelection() {
        if (!savedSelection) return;
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(savedSelection);
    }

    function insertHtml(html) {
        if (currentMode !== 'rich') {
            setMode('rich');
        }
        richEditor.focus();
        restoreSelection();
        document.execCommand('insertHTML', false, html);
        richEditor.dispatchEvent(new Event('input'));
        saveSelection();
    }

    function createInlineImageNote(fileName) {
        return [
            '<p class="lesson-inline-attachment">',
            '<i class="bi bi-image" aria-hidden="true"></i>',
            '<span>Image attachment:</span>',
            '<strong>', escapeHtml(fileName), '</strong>',
            '</p><p><br></p>'
        ].join('');
    }

    function hasInlineAttachmentNote() {
        return Boolean(richEditor.querySelector('.lesson-inline-attachment')) || /Image attachment:/i.test(markdownEditor.value);
    }

    function handleToolbarAction(action, button) {
        if (currentMode !== 'rich') {
            setMode('rich');
        }

        if (action === 'bold') {
            document.execCommand('bold', false);
        } else if (action === 'italic') {
            document.execCommand('italic', false);
        } else if (action === 'heading') {
            document.execCommand('formatBlock', false, '<' + (button.dataset.level || 'h2').toLowerCase() + '>');
        } else if (action === 'unorderedList') {
            document.execCommand('insertUnorderedList', false);
        } else if (action === 'orderedList') {
            document.execCommand('insertOrderedList', false);
        } else if (action === 'quote') {
            insertHtml('<blockquote class="lesson-editor-quote">Highlight an important takeaway or example here.</blockquote><p><br></p>');
            return;
        } else if (action === 'code') {
            insertHtml('<pre class="lesson-editor-code"><code>// Add your code example here</code></pre><p><br></p>');
            return;
        } else if (action === 'table') {
            insertHtml('<div class="lesson-table-wrap"><table class="lesson-editor-table"><thead><tr><th>Column 1</th><th>Column 2</th></tr></thead><tbody><tr><td>Value</td><td>Value</td></tr><tr><td>Value</td><td>Value</td></tr></tbody></table></div><p><br></p>');
            return;
        } else if (action === 'divider') {
            insertHtml('<hr class="lesson-editor-divider"><p><br></p>');
            return;
        } else if (action === 'link') {
            saveSelection();
            const url = window.prompt('Paste the link URL');
            if (!url) return;
            const selection = window.getSelection();
            const selectedText = selection && selection.toString().trim();
            const text = selectedText || window.prompt('Link text', url) || url;
            insertHtml('<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener">' + escapeHtml(text) + '</a>');
            return;
        } else if (action === 'image') {
            saveSelection();
            imageInput.click();
            return;
        }

        richEditor.dispatchEvent(new Event('input'));
        saveSelection();
    }

    function formatBytes(bytes) {
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
        if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB';
        return bytes + ' B';
    }

    function formatLimitLabel(bytes) {
        if (bytes >= 1048576) {
            const mb = bytes / 1048576;
            return (Number.isInteger(mb) ? mb.toFixed(0) : mb.toFixed(1).replace(/\.0$/, '')) + 'MB';
        }
        if (bytes >= 1024) {
            const kb = bytes / 1024;
            return (Number.isInteger(kb) ? kb.toFixed(0) : kb.toFixed(1).replace(/\.0$/, '')) + 'KB';
        }
        return bytes + 'B';
    }

    function getFileIcon(file) {
        const type = file.type || '';
        const ext = (file.name.split('.').pop() || '').toLowerCase();
        if (type.startsWith('image/')) return 'bi bi-image';
        if (type === 'application/pdf' || ext === 'pdf') return 'bi bi-file-earmark-pdf';
        if (type.includes('word') || ['doc', 'docx'].includes(ext)) return 'bi bi-file-earmark-word';
        if (type.includes('excel') || type.includes('sheet') || ['xls', 'xlsx', 'csv'].includes(ext)) return 'bi bi-file-earmark-spreadsheet';
        if (type.includes('presentation') || ['ppt', 'pptx'].includes(ext)) return 'bi bi-file-earmark-slides';
        if (['zip', 'rar'].includes(ext)) return 'bi bi-file-earmark-zip';
        return 'bi bi-file-earmark-text';
    }

    function syncAttachmentInput() {
        const transfer = new DataTransfer();
        attachmentFiles.forEach(file => transfer.items.add(file));
        attachmentInput.files = transfer.files;
        syncAttachmentDebugFields();
    }

    function syncAttachmentDebugFields() {
        if (clientAttachmentCountInput) {
            clientAttachmentCountInput.value = String(attachmentFiles.length);
        }
        if (clientAttachmentNamesInput) {
            clientAttachmentNamesInput.value = attachmentFiles.map(file => file.name).join(' | ');
        }
    }

    function renderAttachments() {
        attachmentPreviewList.innerHTML = '';
        if (!attachmentFiles.length) {
            attachmentPreviewList.innerHTML = '<div class="lesson-file-empty"><i class="bi bi-paperclip"></i><span>No attachments selected yet.</span></div>';
            return;
        }

        attachmentFiles.forEach((file, index) => {
            const card = document.createElement('div');
            card.className = 'lesson-file-card';
            card.innerHTML = [
                '<div class="lesson-file-icon"><i class="', getFileIcon(file), '"></i></div>',
                '<div class="lesson-file-meta">',
                '<strong>', escapeHtml(file.name), '</strong>',
                '<span>', formatBytes(file.size), '</span>',
                '</div>',
                '<button type="button" class="lesson-file-remove" data-file-index="', index, '" aria-label="Remove file">',
                '<i class="bi bi-x-lg"></i>',
                '</button>'
            ].join('');
            attachmentPreviewList.appendChild(card);
        });
    }

    function addFiles(files) {
        const list = Array.from(files || []);
        const nextFiles = attachmentFiles.slice();
        const result = {
            added: [],
            duplicates: [],
            overLimit: [],
            blockedByCount: []
        };

        list.forEach(file => {
            const duplicate = nextFiles.some(existing => existing.name === file.name && existing.size === file.size && existing.lastModified === file.lastModified);
            if (duplicate) {
                result.duplicates.push(file);
                return;
            }
            if (nextFiles.length >= maxFiles) {
                result.blockedByCount.push(file);
                return;
            }
            if (file.size > maxFileSize) {
                result.overLimit.push(file);
                return;
            }
            nextFiles.push(file);
            result.added.push(file);
        });

        attachmentFiles = nextFiles.slice(0, maxFiles);
        syncAttachmentInput();
        renderAttachments();
        if (result.duplicates.length) {
            toast(result.duplicates[0].name + ' is already attached.', 'info');
        }
        if (result.blockedByCount.length) {
            toast('You can attach up to ' + maxFiles + ' files per lesson.', 'info');
        }
        if (result.overLimit.length) {
            toast(result.overLimit[0].name + ' exceeds the ' + formatLimitLabel(maxFileSize) + ' limit.', 'error');
        }
        return result;
    }

    function restoreDraft() {
        let raw = null;
        try {
            raw = localStorage.getItem(draftKey);
        } catch (error) {
            return;
        }
        if (!raw) return;

        try {
            const draft = JSON.parse(raw);
            titleInput.value = draft.title || '';
            richEditor.innerHTML = draft.richContent || '';
            markdownEditor.value = draft.markdownContent || '';
            videoInput.value = draft.videoUrl || '';
            linkTitleInput.value = draft.linkTitle || '';
            linkUrlInput.value = draft.linkUrl || '';
            currentMode = draft.currentMode || 'rich';
            lastEditableMode = draft.lastEditableMode || (currentMode === 'markdown' ? 'markdown' : 'rich');
            if (currentMode === 'markdown') {
                autoGrowMarkdown();
            } else if (currentMode === 'preview') {
                buildPreview();
            }
            updatePlaceholder();
            refreshModeUi();
            setDraftMessage('Draft restored from this browser');
        } catch (error) {
            try {
                localStorage.removeItem(draftKey);
            } catch (removeError) {}
        }
    }

    const saveDraft = debounce(() => {
        try {
            localStorage.setItem(draftKey, JSON.stringify({
                title: titleInput.value,
                richContent: richEditor.innerHTML,
                markdownContent: markdownEditor.value,
                currentMode: currentMode,
                lastEditableMode: lastEditableMode,
                videoUrl: videoInput.value,
                linkTitle: linkTitleInput.value,
                linkUrl: linkUrlInput.value
            }));
            setDraftMessage('Draft saved locally');
        } catch (error) {
            setDraftMessage('Draft too large to autosave');
        }
    }, 450);

    modeButtons.forEach(button => {
        button.addEventListener('click', () => setMode(button.dataset.editorMode));
    });

    toolbar.querySelectorAll('.lesson-toolbar-btn').forEach(button => {
        button.addEventListener('mousedown', event => event.preventDefault());
        button.addEventListener('click', () => handleToolbarAction(button.dataset.editorAction, button));
    });

    richEditor.addEventListener('input', () => {
        lastEditableMode = 'rich';
        updatePlaceholder();
        saveDraft();
    });
    richEditor.addEventListener('keyup', saveSelection);
    richEditor.addEventListener('mouseup', saveSelection);
    richEditor.addEventListener('focus', saveSelection);

    markdownEditor.addEventListener('input', () => {
        lastEditableMode = 'markdown';
        autoGrowMarkdown();
        saveDraft();
    });

    [titleInput, videoInput, linkTitleInput, linkUrlInput].forEach(input => {
        input.addEventListener('input', saveDraft);
    });

    imageInput.addEventListener('change', event => {
        const file = event.target.files && event.target.files[0];
        if (!file) return;
        if (!file.type.startsWith('image/')) {
            toast('Please choose a valid image file.', 'error');
            imageInput.value = '';
            return;
        }
        const result = addFiles([file]);
        if (result.added.length) {
            insertHtml(createInlineImageNote(file.name));
            toast(file.name + ' added to attachments.', 'success');
        }
        imageInput.value = '';
    });

    function openAttachmentPicker() {
        if (!attachmentPicker) return;
        attachmentPicker.value = '';
        attachmentPicker.click();
    }

    attachmentDropzone.addEventListener('click', openAttachmentPicker);
    attachmentDropzone.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openAttachmentPicker();
        }
    });
    attachmentDropzone.addEventListener('dragover', event => {
        event.preventDefault();
        attachmentDropzone.classList.add('is-dragover');
    });
    attachmentDropzone.addEventListener('dragleave', () => {
        attachmentDropzone.classList.remove('is-dragover');
    });
    attachmentDropzone.addEventListener('drop', event => {
        event.preventDefault();
        attachmentDropzone.classList.remove('is-dragover');
        addFiles(event.dataTransfer.files);
    });
    if (attachmentPicker) {
        attachmentPicker.addEventListener('change', event => {
            addFiles(event.target.files);
            attachmentPicker.value = '';
        });
    }
    attachmentPreviewList.addEventListener('click', event => {
        const removeButton = event.target.closest('.lesson-file-remove');
        if (!removeButton) return;
        attachmentFiles.splice(Number(removeButton.dataset.fileIndex), 1);
        syncAttachmentInput();
        renderAttachments();
    });

    form.addEventListener('submit', () => {
        syncAttachmentDebugFields();
        syncHiddenContent();
        try {
            localStorage.removeItem(draftKey);
        } catch (error) {}
        setDraftMessage('Draft cleared');
    });

    modal.addEventListener('shown.bs.modal', () => {
        restoreDraft();
        renderAttachments();
        updatePlaceholder();
        if (!attachmentFiles.length && hasInlineAttachmentNote()) {
            setDraftMessage('Draft restored. Re-attach image files before publishing');
        }
        if (!titleInput.value) {
            titleInput.focus();
        }
    });

    modal.addEventListener('hidden.bs.modal', () => {
        attachmentDropzone.classList.remove('is-dragover');
    });

    updatePlaceholder();
    autoGrowMarkdown();
    syncAttachmentDebugFields();
    renderAttachments();
})();
