<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Modules\Social\Application\Attachments;
use App\Modules\Social\Application\SocialRules;
use App\Support\HrisFormat;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Photos and files waiting on a draft before it's sent. The Community post
 * box, every comment box, the Chat composer and the group photo pickers all
 * take them the same way, so they share this.
 *
 * Each draft has a slot: `post`, `chat`, `c-<post id>` for a comment,
 * `r-<comment id>` for a reply, `e-<post id>` for what an edit adds to a
 * post, `avatar` for a group photo. The picker
 * (resources/js/attachment-picker.js) uploads each file into
 * `picked.<slot>`, and updatedPicked() checks it and moves it onto
 * `staged[<slot>]`, so a second pick adds to the first instead of replacing
 * it. A group photo slot only ever holds one.
 *
 * Usage:
 *
 *     class ChatPage extends Component
 *     {
 *         use WithFileUploads, WithStagedAttachments;
 *     }
 *
 *     <div x-data="attachmentPicker({ maxBytes: @js($maxBytes), slot: 'chat' })">
 *         <input type="file" multiple x-on:change="pick($event)" />
 *     </div>
 */
trait WithStagedAttachments
{
    /** @var array<array-key, mixed> The picker's uploads, by slot. updatedPicked() moves them onto the staged list the moment they land. */
    public array $picked = [];

    /** @var array<string, list<TemporaryUploadedFile>> What's waiting on each draft, by slot, each file already checked. */
    #[Locked]
    public array $staged = [];

    public function updatedPicked(): void
    {
        foreach ($this->picked as $slot => $files) {
            // A slot is a draft this page has, never a path of the browser's choosing.
            if (! is_string($slot) || preg_match('/^(post|chat|avatar|[cre]-[A-Za-z0-9-]{1,40})$/', $slot) !== 1) {
                continue;
            }

            foreach (is_array($files) ? $files : [$files] as $file) {
                if (! $file instanceof TemporaryUploadedFile) {
                    continue;
                }

                $problem = $this->stagingProblem($file, $slot);
                if ($problem !== null) {
                    Flux::toast(text: $problem, variant: 'danger');

                    continue;
                }

                if ($slot === 'avatar') {
                    $this->staged['avatar'] = [$file];
                } else {
                    $this->staged[$slot] = [...($this->staged[$slot] ?? []), $file];
                }
            }
        }

        $this->picked = [];
    }

    public function unstage(string $slot, int $index): void
    {
        $this->staged[$slot] = array_values(array_filter(
            $this->staged[$slot] ?? [],
            static fn (int $i): bool => $i !== $index,
            ARRAY_FILTER_USE_KEY,
        ));

        if ($this->staged[$slot] === []) {
            unset($this->staged[$slot]);
        }
    }

    /**
     * What's waiting on a draft, for sending it.
     *
     * @return list<TemporaryUploadedFile>
     */
    protected function stagedFor(string $slot): array
    {
        return $this->staged[$slot] ?? [];
    }

    protected function clearStaged(string $slot): void
    {
        unset($this->staged[$slot]);
    }

    /** The app-wide per-file cap. The picker shrinks photos under it before upload; this is the check that counts. */
    protected function stagedFileLimit(): int
    {
        return Attachments::maxFileBytes();
    }

    private function stagingProblem(TemporaryUploadedFile $file, string $slot): ?string
    {
        $found = Attachments::identify($file);

        if ($found === null || ($slot === 'avatar' && $found[0] !== 'PHOTO')) {
            return $slot === 'avatar' ? 'Pick a photo: JPEG, PNG, GIF or WebP.' : Attachments::REFUSED;
        }

        $size = (int) $file->getSize();
        if ($size > $this->stagedFileLimit()) {
            return $file->getClientOriginalName().' is '.HrisFormat::bytes($size).'. The limit is '.HrisFormat::bytes($this->stagedFileLimit()).'.';
        }

        if ($slot !== 'avatar' && count($this->staged[$slot] ?? []) >= SocialRules::MAX_ATTACHMENTS) {
            return 'Up to '.SocialRules::MAX_ATTACHMENTS.' photos and files at a time.';
        }

        return null;
    }
}
