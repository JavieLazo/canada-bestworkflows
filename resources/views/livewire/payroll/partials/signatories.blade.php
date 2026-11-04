{{-- The signature blocks a payroll document is signed with: Prepared by, Checked by, Certified correct by (SignatoryRecords, in print order).
     A slot nobody has set prints as a blank line to be filled by hand; nothing here is an approval. Takes `signatories`, a list of SignatoryView. --}}
<footer class="hris-signatories mt-6 grid grid-cols-3 gap-4 border-t border-border pt-4 text-xs" aria-label="Signatories">
    @foreach ($signatories as $signatory)
        <div class="min-w-0" data-signatory="{{ $signatory->slot }}">
            <p class="text-muted-foreground">{{ $signatory->label }}:</p>
            <div class="mt-8 border-b border-foreground"></div>
            <p class="mt-1 truncate text-center font-semibold">{{ $signatory->name !== '' ? $signatory->name : "\u{00A0}" }}</p>
            <p class="truncate text-center text-muted-foreground">{{ $signatory->designation !== '' ? $signatory->designation : "\u{00A0}" }}</p>
        </div>
    @endforeach
</footer>
