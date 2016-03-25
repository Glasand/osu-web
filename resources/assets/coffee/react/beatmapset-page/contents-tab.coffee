###
# Copyright 2016 ppy Pty. Ltd.
#
# This file is part of osu!web. osu!web is distributed with the hope of
# attracting more community contributions to the core ecosystem of osu!.
#
# osu!web is free software: you can redistribute it and/or modify
# it under the terms of the Affero GNU General Public License version 3
# as published by the Free Software Foundation.
#
# osu!web is distributed WITHOUT ANY WARRANTY; without even the implied
# warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
# See the GNU Affero General Public License for more details.
#
# You should have received a copy of the GNU Affero General Public License
# along with osu!web.  If not, see <http://www.gnu.org/licenses/>.
###
{a} = React.DOM

class BeatmapSetPage.ContentsTab extends React.Component
  onClick: (e) =>
    return if @props.count == 0
    e.preventDefault()
    $.publish 'beatmapset:playmode:set', @props.playmode
    $.publish 'beatmapset:mode:set', @props.newMode

  render: ->
    active = @props.playmode == @props.currentPlaymode

    className = 'page-tabs__tab'
    className += ' page-tabs__tab--active' if active
    className += ' page-tabs__tab--disabled' if @props.disabled

    url = BeatmapSetPageHash.generate
      mode: if active then @props.currentMode else @props.newMode
      page: @props.currentPage

    a
      className: className
      onClick: @onClick if not active
      href: url if not @props.disabled
      Lang.get "beatmaps.mode.#{Mode.toString @props.playmode}"
