{{--
    The preference centre, shared by /preferences/{token} and the page a
    tokenized unsubscribe link lands on.

    Everything the server said is rendered. Errors that belong to a row appear
    under that row; anything else lands in the block above the form, so no
    rejection can fall through the floor — the rule 1.5.3 established for the
    control panel, which matters more out here: a reader who cannot see why a
    change was refused does not open a ticket, they report the mail as spam.

    `data-list` and `data-state` are on the rows on purpose. They are what a
    check reads to say what the page is showing, instead of a person squinting
    at a screenshot.
--}}
@php
    $rowErrorKeys = $center->rows->map(fn ($row) => 'lists.'.$row->handle())->all();
    $loose = collect($errors->getMessages())
        ->reject(fn ($messages, $key) => in_array($key, $rowErrorKeys, true))
        ->flatten();
@endphp

<div class="prefs">
    @if (session('marketing.status'))
        <p class="notice" role="status">{{ session('marketing.status') }}</p>
    @endif

    @if ($loose->isNotEmpty())
        <ul class="errors" role="alert">
            @foreach ($loose as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    @endif

    <p class="muted">{{ __('marketing::public.preferences_intro', ['email' => $center->email()]) }}</p>

    @if ($center->rows->isEmpty())
        <p class="muted">{{ __('marketing::public.preferences_empty') }}</p>
    @else
        <form method="POST" action="{{ route('marketing.preferences.update', $center->token()) }}">
            @csrf
            <input type="hidden" name="action" value="save">

            <h2>{{ __('marketing::public.preferences_lists_heading') }}</h2>

            <ul class="list">
                @foreach ($center->rows as $row)
                    <li class="row{{ $row->suppressed ? ' is-blocked' : '' }}"
                        data-list="{{ $row->handle() }}"
                        data-state="{{ $row->suppressed ? 'blocked' : ($row->active ? 'active' : 'inactive') }}">
                        <label>
                            <input type="checkbox" name="lists[]" value="{{ $row->handle() }}"
                                @checked($row->active) @disabled($row->suppressed)>
                            <span class="name">{{ $row->list->name }}</span>
                        </label>
                        @if ($row->list->description)
                            <span class="desc">{{ $row->list->description }}</span>
                        @endif
                        @if ($row->suppressed)
                            <span class="blocked">{{ __('marketing::public.preferences_blocked') }}</span>
                        @endif
                        @error('lists.'.$row->handle())
                            <span class="error">{{ $message }}</span>
                        @enderror
                    </li>
                @endforeach
            </ul>

            <button type="submit" class="btn">{{ __('marketing::public.preferences_save') }}</button>
        </form>

        {{--
            Deliberately a second form, below a rule, with its own wording. It
            is not one checkbox among the others: it ends every list of this
            brand at once, and something with that reach should not be reachable
            by mis-clicking a row.
        --}}
        <form method="POST" action="{{ route('marketing.preferences.update', $center->token()) }}" class="all">
            @csrf
            <input type="hidden" name="action" value="unsubscribe_all">

            <h2>{{ __('marketing::public.preferences_all_heading') }}</h2>
            <p class="muted">{{ __('marketing::public.preferences_all_body') }}</p>

            <button type="submit" class="btn btn-quiet" data-action="unsubscribe-all">
                {{ __('marketing::public.preferences_all_button') }}
            </button>
        </form>
    @endif
</div>
