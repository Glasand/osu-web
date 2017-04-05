{{--
    Copyright 2015-2017 ppy Pty. Ltd.

    This file is part of osu!web. osu!web is distributed with the hope of
    attracting more community contributions to the core ecosystem of osu!.

    osu!web is free software: you can redistribute it and/or modify
    it under the terms of the Affero GNU General Public License version 3
    as published by the Free Software Foundation.

    osu!web is distributed WITHOUT ANY WARRANTY; without even the implied
    warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
    See the GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with osu!web.  If not, see <http://www.gnu.org/licenses/>.
--}}
<div class="changelog-header__stream {{ $featured ? 'changelog-header__stream--featured' : '' }} changelog-stream {{ $stream_id === $stream->stream_id ? 'changelog-stream--active' : '' }} changelog-stream--{{ str_slug($stream->pretty_name) }}">
    <a class="changelog-stream__link" href={{route('changelog', ['stream_id' => $stream->stream_id])}}></a>
    <div class="changelog-stream__content">
        <span class="changelog-stream__name">{{ $stream->pretty_name }}</span>
        <span class="changelog-stream__build">{{ $stream->version }}</span>
        <span class="changelog-stream__users">{{ trans_choice('changelog.users-online', $stream->users, ['users' => $stream->users]) }}</span>
    </div>
    <div class="changelog-stream__indicator-box">
        <div class="changelog-stream__indicator">
        </div>
    </div>
</div>
