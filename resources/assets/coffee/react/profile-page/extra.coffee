###
# Copyright 2015 ppy Pty. Ltd.
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
{div} = React.DOM
el = React.createElement

class ProfilePage.Extra extends React.Component
  constructor: (props) ->
    withMePage = props.userPage.html != '' || props.withEdit

    if not withMePage
      index = props.profileOrder.indexOf 'me'
      props.profileOrder.splice index, 1

    super props

    @state =
      tabsSticky: false
      profileOrder: @props.profileOrder
      draggingEnabled: false

  @childContextTypes:
    withEdit: React.PropTypes.bool

  componentDidMount: =>
    @_removeListeners()
    $.subscribe 'profilePageExtra:tab.profileContentsExtra', @_modeSwitch
    $.subscribe 'stickyHeader.profileContentsExtra', @_tabsStick
    $.subscribe 'profilePageExtra:toggleDragging', @_toggleDragging
    $(window).on 'throttled-scroll.profileContentsExtra', @_modeScan
    osu.pageChange()
    @_modeScan()

    $('#profile-extra-list').sortable({
      disabled: true,
      cursor: 'move',
      revert: 150,
      scrollSpeed: 10,
      update: (event, ui) =>
        newOrder = $('#profile-extra-list').sortable('toArray')
        @setState profileOrder: newOrder
      })
  componentWillUnmount: =>
    @_removeListeners()


  componentWillReceiveProps: =>
    osu.pageChange()


  _modeScan: =>
    return if @_scrolling

    pages = document.getElementsByClassName('js-profile-page-extra--scrollspy')
    return unless pages.length

    currentPage = null
    anchorHeight = window.innerHeight * 0.5

    if osu.bottomPage()
      @setState mode: _.last(pages).getAttribute('id')
      return

    # FIXME: I don't remember why this one scans from bottom while
    # the one in forum.refreshCounter does it from top.
    for page in pages by -1
      pageTop = page.getBoundingClientRect().top
      continue unless pageTop <= anchorHeight

      @setState mode: page.getAttribute('id')
      return

    @setState mode: page.getAttribute('id')


  _modeSwitch: (_e, mode) =>
    # Don't bother scanning the current position.
    # The result will be wrong when target page is too short anyway.
    @_scrolling = true

    $.scrollTo "##{mode}", 500,
      onAfter: =>
        # Manually set the mode to avoid confusion (wrong highlight).
        # Scrolling will obviously break it but that's unfortunate result
        # from having the scrollspy marker at middle of page.
        @setState mode: mode, =>
          # Doesn't work:
          # - part of state (callback, part of mode setting)
          # - simple variable in callback
          # Both still change the switch too soon.
          setTimeout (=> @_scrolling = false), 100
      # count for the tabs height
      offset: @refs.tabs.getBoundingClientRect().height * -1


  _removeListeners: ->
    $.unsubscribe '.profileContentsExtra'
    $(window).off '.profileContentsExtra'


  _tabsStick: (_e, target) =>
    @setState tabsSticky: (target == 'profile-extra-tabs')

  _toggleDragging: =>
    if @state.draggingEnabled

      osu.showLoadingOverlay()

      $.ajax '/account/update-profile', {
        method: 'PUT',
        dataType: 'JSON',
        data: {
          'order': @state.profileOrder,
        },
        success: (data, textStatus, jqHXR) ->
          osu.hideLoadingOverlay()
      }

      $('#profile-extra-list').sortable('disable')

      @setState draggingEnabled: false
    else
      $('#profile-extra-list').sortable('enable')
      @setState draggingEnabled: true

  getChildContext: ->
    return {withEdit: @props.withEdit}

  render: =>
    return if @props.mode == 'me'

    tabsContainerClasses = 'profile-extra-tabs__container js-fixed-element'
    tabsClasses = 'profile-extra-tabs__items'
    if @state.tabsSticky
      tabsContainerClasses += ' profile-extra-tabs__container--fixed js-sticky-header--active'
      tabsClasses += ' profile-extra-tabs__items--fixed'

    div className: 'osu-layout__section osu-layout__section--extra',
      div
        className: 'profile-extra-tabs js-sticky-header'
        'data-sticky-header-target': 'profile-extra-tabs'
        ref: 'tabs'
        div
          className: tabsContainerClasses
          div className: 'osu-layout__row',
            div
              className: tabsClasses
              'data-sticky-header-id': 'profile-extra-tabs'
              @state.profileOrder.map (m) =>

                el ProfilePage.ExtraTab, key: m, mode: m, currentMode: @state.mode

      div {id: 'profile-extra-list'},
        @props.profileOrder.map (m) =>
          switch m
            when 'me'
              div
                className: 'osu-layout__row js-profile-page-extra--scrollspy'
                id: 'me'
                el ProfilePage.UserPage, userPage: @props.userPage, withEdit: @props.withEdit, user: @props.user
            when 'recent_activities'
              div
                className: 'osu-layout__row js-profile-page-extra--scrollspy'
                id: 'recent_activities'
                el ProfilePage.RecentActivities, recentActivities: @props.recentActivities
            when 'kudosu'
              div
                className: 'osu-layout__row js-profile-page-extra--scrollspy'
                id: 'kudosu'
                el ProfilePage.Kudosu, user: @props.user, recentlyReceivedKudosu: @props.recentlyReceivedKudosu
            when 'top_ranks'
              div
                className: 'osu-layout__row js-profile-page-extra--scrollspy'
                id: 'top_ranks'
                el ProfilePage.TopRanks, user: @props.user, scoresBest: @props.scoresBest, scoresFirst: @props.scoresFirst
            when 'beatmaps'
              div
                className: 'osu-layout__row js-profile-page-extra--scrollspy'
                id: 'beatmaps'
                el ProfilePage.Beatmaps,
                  favouriteBeatmapSets: @props.favouriteBeatmapSets
                  rankedAndApprovedBeatmapSets: @props.rankedAndApprovedBeatmapSets
            when 'medals'
              div
                className: 'osu-layout__row js-profile-page-extra--scrollspy'
                id: 'medals'
                el ProfilePage.Medals, achievements: @props.achievements, allAchievements: @props.allAchievements
            when 'historical'
              div
                className: 'osu-layout__row js-profile-page-extra--scrollspy'
                id: 'historical'
                el ProfilePage.Historical,
                  beatmapPlaycounts: @props.beatmapPlaycounts
                  rankHistories: @props.rankHistories
                  scores: @props.scores
