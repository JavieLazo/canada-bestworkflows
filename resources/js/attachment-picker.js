/*
 * `attachmentPicker` gets photos and files ready to upload, for the Community
 * post box, the comment boxes, the Chat composer, and group photos.
 *
 * It checks what was picked before anything uploads, so a problem gets one
 * message instead of one per file: files that aren't photos or everyday
 * office files are left out, and so is anything past the ten a post, comment
 * or message can hold.
 *
 * Photos are shrunk in the browser first. Every upload in cop-ems is capped
 * at 2 MB (copems.uploads.max_file_bytes), and a phone photo is usually 3 to
 * 8 MB, so without this most of them would be refused. A photo longer than
 * 2048 px, or heavier than the cap, is redrawn at most 2048 px on its longest
 * side as a JPEG over white. A GIF is sent as it is, so it keeps moving. The
 * server checks everything again whatever happens here, and shrinks anything
 * that still arrives too big.
 *
 * One file per request, because PHP refuses a whole request larger than its
 * post_max_size, which is 2 MB out of the box on plenty of hosts. Each file
 * goes up into `picked.<slot>`, where the slot says which draft it's for, and
 * WithStagedAttachments moves it onto that draft.
 */

/*
 * Longest edge and JPEG quality, tried in order until the result fits under
 * the cap. An ordinary photo fits on the first; only something unusually
 * detailed (a busy screenshot, a photo of gravel) gets smaller than 2048 px.
 */
const ATTEMPTS = [[2048, 0.85], [2048, 0.72], [1600, 0.72], [1280, 0.7]];
const MAX_EDGE = ATTEMPTS[0][0];

const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
const FILE_EXTENSIONS = ['pdf', 'docx', 'xlsx', 'pptx', 'txt', 'csv'];

const extension = (file) => (file.name.split('.').pop() ?? '').toLowerCase();

const isPhoto = (file) => PHOTO_TYPES.includes(file.type) || (file.type === '' && ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(extension(file)));

const isFile = (file) => FILE_EXTENSIONS.includes(extension(file));

const megabytes = (bytes) => (bytes / 1000000).toFixed(1) + ' MB';

/** An ImageBitmap where the browser has one, an <img> where it does not. Null when it cannot be read. */
async function decode(file) {
    if ('createImageBitmap' in window) {
        try {
            return await createImageBitmap(file);
        } catch {
            // Fall through to the <img> route, which reads a few more formats.
        }
    }

    const url = URL.createObjectURL(file);
    try {
        const img = new Image();
        img.src = url;
        await img.decode();
        return img;
    } catch {
        return null;
    } finally {
        URL.revokeObjectURL(url);
    }
}

/** The picture redrawn at most `edge` on its longest side, as a JPEG over white. */
async function draw(source, width, height, edge, quality) {
    const scale = Math.min(1, edge / Math.max(width, height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(width * scale));
    canvas.height = Math.max(1, Math.round(height * scale));
    const context = canvas.getContext('2d');
    // Over white: a transparent PNG's clear corners would turn black as a JPEG.
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(source, 0, 0, canvas.width, canvas.height);

    return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
}

async function shrink(file, maxBytes) {
    if (file.type === 'image/gif') {
        return file;
    }

    const source = await decode(file);
    if (source === null) {
        return file;
    }

    const width = source.naturalWidth || source.width;
    const height = source.naturalHeight || source.height;

    if (Math.max(width, height) <= MAX_EDGE && file.size <= maxBytes) {
        source.close?.();
        return file;
    }

    let blob = null;
    for (const [edge, quality] of ATTEMPTS) {
        blob = await draw(source, width, height, edge, quality);
        if (blob === null || blob.size <= maxBytes) {
            break;
        }
    }
    source.close?.();

    if (blob === null) {
        return file;
    }

    return new File([blob], file.name.replace(/\.[^./]+$/, '') + '.jpg', { type: 'image/jpeg', lastModified: Date.now() });
}

/**
 * @param {object} options
 * @param {number} options.maxBytes  the app's per-file cap
 * @param {string} options.slot  which draft these are for: `post`, `chat`, `c-<post>`, `r-<comment>`, `avatar`
 * @param {number} [options.max]  how many the draft can hold
 * @param {boolean} [options.photosOnly]  a group photo, not a post
 */
export default function attachmentPicker({ maxBytes, slot, max = 10, photosOnly = false }) {
    return {
        busy: false,

        /** How many the draft already holds, from what the server staged. */
        held() {
            return (this.$wire.staged?.[slot] ?? []).length;
        },

        async pick(event) {
            const input = event.target;
            const files = Array.from(input.files ?? []);
            // Cleared at once, so picking the same file again still fires `change`.
            input.value = '';

            if (files.length === 0) {
                return;
            }

            const refused = [];
            const tooBig = [];
            let ready = [];

            for (const file of files) {
                if (isPhoto(file)) {
                    ready.push(file);
                } else if (!photosOnly && isFile(file)) {
                    if (file.size > maxBytes) {
                        tooBig.push(file);
                    } else {
                        ready.push(file);
                    }
                } else {
                    refused.push(file);
                }
            }

            const room = photosOnly ? 1 : Math.max(0, max - this.held());
            const left = Math.max(0, ready.length - room);
            ready = ready.slice(0, room);

            // One message for everything that was left out, not one per file.
            const problems = [];
            if (refused.length > 0) {
                problems.push(photosOnly
                    ? 'Pick a photo: JPEG, PNG, GIF or WebP.'
                    : `${refused.length === 1 ? 'One file was' : refused.length + ' files were'} left out. Only photos and PDF, Word, Excel, PowerPoint, text or CSV files can be added.`);
            }
            if (tooBig.length > 0) {
                problems.push(`${tooBig.length === 1 ? tooBig[0].name + ' is' : tooBig.length + ' files are'} over the ${megabytes(maxBytes)} limit.`);
            }
            if (left > 0) {
                problems.push(`Up to ${max} photos and files at a time, so ${left === 1 ? 'one was' : left + ' were'} left out.`);
            }
            if (problems.length > 0) {
                window.Flux?.toast({ text: problems.join(' '), variant: 'danger' });
            }

            this.busy = ready.length > 0;
            try {
                for (const file of ready) {
                    const upload = isPhoto(file) ? await shrink(file, maxBytes) : file;

                    await new Promise((resolve) => this.$wire.uploadMultiple('picked.' + slot, [upload], resolve, () => {
                        window.Flux?.toast({ text: `${file.name} could not be uploaded. Please try again.`, variant: 'danger' });
                        resolve();
                    }));
                }
            } finally {
                this.busy = false;
            }
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('attachmentPicker', attachmentPicker);
});
