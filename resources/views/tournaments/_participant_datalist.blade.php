<datalist id="participantSuggestions" data-suggest-url="{{ route('participants.suggest') }}">
    @foreach ($participantSuggestions ?? [] as $suggestion)
        <option value="{{ $suggestion }}"></option>
    @endforeach
</datalist>
