/*
 * When a paste runs past a box's `maxlength`, the browser quietly keeps the
 * start and drops the rest. Someone in a hurry could post half of what they
 * meant to and never notice. So this says so, with the limit, the moment it
 * happens.
 *
 * A box opts in by naming what it holds, which the message uses:
 *
 *     <textarea maxlength="5000" data-paste-limit="A post"></textarea>
 *
 *     "Your paste was cut short. A post holds up to 5,000 characters, so the
 *      rest was left out."
 *
 * The server refuses anything longer on its own, so this is only here so
 * nobody is surprised.
 */

document.addEventListener('paste', (event) => {
    const box = event.target;

    if (!(box instanceof HTMLTextAreaElement || box instanceof HTMLInputElement) || !box.dataset.pasteLimit) {
        return;
    }

    const max = box.maxLength;
    // Counted the way the box will store it: a textarea turns \r\n into \n,
    // and a one-line box drops line breaks altogether.
    const raw = event.clipboardData?.getData('text') ?? '';
    const pasted = box instanceof HTMLTextAreaElement ? raw.replace(/\r\n?/g, '\n') : raw.replace(/[\r\n]/g, '');
    // Whatever is selected gets replaced by the paste, so it doesn't count.
    const kept = box.value.length - Math.abs((box.selectionEnd ?? 0) - (box.selectionStart ?? 0));

    if (max < 1 || kept + pasted.length <= max) {
        return;
    }

    window.Flux?.toast({
        heading: 'Your paste was cut short',
        text: `${box.dataset.pasteLimit} holds up to ${max.toLocaleString('en-US')} characters, so the rest was left out.`,
        variant: 'warning',
    });
});
