<?php

/**
 *    Copyright 2015-2017 ppy Pty. Ltd.
 *
 *    This file is part of osu!web. osu!web is distributed with the hope of
 *    attracting more community contributions to the core ecosystem of osu!.
 *
 *    osu!web is free software: you can redistribute it and/or modify
 *    it under the terms of the Affero GNU General Public License version 3
 *    as published by the Free Software Foundation.
 *
 *    osu!web is distributed WITHOUT ANY WARRANTY; without even the implied
 *    warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *    See the GNU Affero General Public License for more details.
 *
 *    You should have received a copy of the GNU Affero General Public License
 *    along with osu!web.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace App\Models;

use App\Interfaces\Messageable;
use App\Libraries\BBCodeForDB;
use App\Models\Chat\PrivateMessage;
use App\Traits\UserAvatar;
use App\Traits\Validatable;
use Cache;
use Carbon\Carbon;
use DB;
use Exception;
use Hash;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Laravel\Passport\HasApiTokens;
use Request;

class User extends Model implements AuthenticatableContract, Messageable
{
    use HasApiTokens, Authenticatable, UserAvatar, Validatable;

    protected $table = 'phpbb_users';
    protected $primaryKey = 'user_id';
    protected $guarded = [];

    protected $dates = ['user_regdate', 'user_lastvisit', 'user_lastpost_time'];
    protected $dateFormat = 'U';
    public $timestamps = false;

    protected $visible = ['user_id', 'username', 'username_clean', 'user_rank', 'osu_playstyle', 'user_colour'];

    protected $casts = [
        'osu_subscriber' => 'boolean',
        'user_timezone', 'float',
    ];

    const PLAYSTYLES = [
        'mouse' => 1,
        'keyboard' => 2,
        'tablet' => 4,
        'touch' => 8,
    ];

    const SEARCH_DEFAULTS = [
        'query' => null,
        'limit' => 20,
        'page' => 1,
    ];

    const CACHING = [
        'follower_count' => [
            'key' => 'followerCount',
            'duration' => 720, // 12 hours
        ],
    ];

    private $memoized = [];
    private $validateCurrentPassword = false;
    private $validatePasswordConfirmation = false;
    private $password = null;
    private $passwordConfirmation = null;
    private $currentPassword = null;

    private $emailConfirmation = null;
    private $validateEmailConfirmation = false;

    public function getAuthPassword()
    {
        return $this->user_password;
    }

    public function usernameChangeCost()
    {
        $changesToDate = $this->usernameChangeHistory()
            ->whereIn('type', ['support', 'paid'])
            ->count();

        switch ($changesToDate) {
            case 0: return 0;
            case 1: return 8;
            case 2: return 16;
            case 3: return 32;
            case 4: return 64;
            default: return 100;
        }
    }

    public static function checkWhenUsernameAvailable($username)
    {
        $user = self::whereIn('username', [str_replace(' ', '_', $username), str_replace('_', ' ', $username)])->first();

        if ($user === null) {
            $lastUsage = UsernameChangeHistory::where('username_last', $username)
                ->orderBy('change_id', 'desc')
                ->first();

            if ($lastUsage === null) {
                return Carbon::now();
            }

            return Carbon::parse($lastUsage->timestamp)->addMonths(6);
        }

        if ($user->group_id !== 2 || $user->user_type === 1) {
            //reserved usernames
            return Carbon::now()->addYears(10);
        }

        $playCount = array_reduce(array_keys(Beatmap::MODES), function ($result, $mode) use ($user) {
            return $result + $user->statistics($mode, true)->value('playcount');
        }, 0);

        return $user->user_lastvisit
            ->addMonths(6)                 //base inactivity period for all accounts
            ->addDays($playCount * 0.75);  //bonus based on playcount
    }

    public static function validateUsername($username, $previousUsername = null)
    {
        if (present($previousUsername) && $previousUsername === $username) {
            // no change
            return [];
        }

        if ($username !== trim($username)) {
            return ["Username can't start or end with spaces!"];
        }

        if (strlen($username) < 3) {
            return ['The requested username is too short.'];
        }

        if (strlen($username) > 15) {
            return ['The requested username is too long.'];
        }

        if (strpos($username, '  ') !== false || !preg_match('#^[A-Za-z0-9-\[\]_ ]+$#u', $username)) {
            return ['The requested username contains invalid characters.'];
        }

        if (strpos($username, '_') !== false && strpos($username, ' ') !== false) {
            return ['Please use either underscores or spaces, not both!'];
        }

        foreach (model_pluck(DB::table('phpbb_disallow'), 'disallow_username') as $check) {
            if (preg_match('#^'.str_replace('%', '.*?', preg_quote($check, '#')).'$#i', $username)) {
                return ['This username choice is not allowed.'];
            }
        }

        if (($availableDate = self::checkWhenUsernameAvailable($username)) > Carbon::now()) {
            $remaining = Carbon::now()->diff($availableDate, false);

            if ($remaining->days > 365 * 2) {
                //no need to mention the inactivity period of the account is actively in use.
                return ['Username is already in use!'];
            } elseif ($remaining->days > 0) {
                return ["This username will be available for use in <strong>{$remaining->days}</strong> days."];
            } elseif ($remaining->h > 0) {
                return ["This username will be available for use in <strong>{$remaining->h}</strong> hours."];
            } else {
                return ['This username will be available for use any minute now!'];
            }
        }

        return [];
    }

    public static function search($rawParams)
    {
        $max = config('osu.search.max.user');

        $params = [];
        $params['query'] = presence($rawParams['query'] ?? null);
        $params['limit'] = clamp(get_int($rawParams['limit'] ?? null) ?? static::SEARCH_DEFAULTS['limit'], 1, 50);
        $params['page'] = max(1, get_int($rawParams['page'] ?? 1));

        $query = static::where('username', 'LIKE', mysql_escape_like($params['query']).'%')
            ->where('username', 'NOT LIKE', '%\_old')
            ->default();

        $overLimit = (clone $query)->limit(1)->offset($max)->exists();
        $total = $overLimit ? $max : $query->count();
        $end = $params['page'] * $params['limit'];
        // Actual limit for query.
        // Don't change the params because it's used for pagination.
        $limit = $params['limit'];
        if ($end > $max) {
            // Ensure $max is honored.
            $limit -= ($end - $max);
            // Avoid negative limit.
            $limit = max(0, $limit);
        }
        $offset = $end - $limit;

        return [
            'total' => $total,
            'over_limit' => $overLimit,
            'data' => $query
                ->orderBy('user_id', 'ASC')
                ->limit($limit)
                ->offset($offset)
                ->get(),
            'params' => $params,
        ];
    }

    public function validateUsernameChangeTo($username)
    {
        if (!$this->hasSupported()) {
            return ["You must have <a href='http://osu.ppy.sh/p/support'>supported osu!</a> to change your name!"];
        }

        if ($username === $this->username) {
            return ['This is already your username, silly!'];
        }

        return self::validateUsername($username);
    }

    // verify that an api key is correct
    public function verify($key)
    {
        return $this->api->api_key === $key;
    }

    public static function lookup($username_or_id, $lookup_type = null, $find_all = false)
    {
        if (!present($username_or_id)) {
            return;
        }

        switch ($lookup_type) {
            case 'string':
                $user = self::where('username', $username_or_id)->orWhere('username_clean', '=', $username_or_id);
                break;

            case 'id':
                $user = self::where('user_id', $username_or_id);
                break;

            default:
                if (is_numeric($username_or_id)) {
                    $user = self::where('user_id', $username_or_id);
                } else {
                    $user = self::where('username', $username_or_id)->orWhere('username_clean', '=', $username_or_id);
                }
                break;
        }

        if (!$find_all) {
            $user = $user->where('user_type', 0)->where('user_warnings', 0);
        }

        return $user->first();
    }

    public function getCountryAcronymAttribute($value)
    {
        return presence($value);
    }

    public function getUserFromAttribute($value)
    {
        return presence(htmlspecialchars_decode($value));
    }

    public function setUserFromAttribute($value)
    {
        $this->attributes['user_from'] = e($value);
    }

    public function getUserInterestsAttribute($value)
    {
        return presence(htmlspecialchars_decode($value));
    }

    public function setUserInterestsAttribute($value)
    {
        $this->attributes['user_interests'] = e($value);
    }

    public function getUserOccAttribute($value)
    {
        return presence(htmlspecialchars_decode($value));
    }

    public function setUserOccAttribute($value)
    {
        $this->attributes['user_occ'] = e($value);
    }

    public function setUserSigAttribute($value)
    {
        $bbcode = new BBCodeForDB($value);
        $this->attributes['user_sig'] = $bbcode->generate();
        $this->attributes['user_sig_bbcode_uid'] = $bbcode->uid;
    }

    public function setUserWebsiteAttribute($value)
    {
        $value = trim($value);
        if (!starts_with($value, ['http://', 'https://'])) {
            $value = "https://{$value}";
        }

        $this->attributes['user_website'] = $value;
    }

    public function setOsuPlaystyleAttribute($value)
    {
        $styles = 0;

        foreach (self::PLAYSTYLES as $type => $bit) {
            if (in_array($type, $value, true)) {
                $styles += $bit;
            }
        }

        $this->attributes['osu_playstyle'] = $styles;
    }

    public function isSpecial()
    {
        return $this->user_id !== null && present($this->user_colour);
    }

    public function getUserBirthdayAttribute($value)
    {
        if (presence($value) === null) {
            return;
        }

        $date = explode('-', $value);
        $date = array_map(function ($x) {
            return (int) trim($x);
        }, $date);
        if ($date[2] === 0) {
            return;
        }

        return Carbon::create($date[2], $date[1], $date[0]);
    }

    public function age()
    {
        return $this->user_birthday->age ?? null;
    }

    public function cover()
    {
        return $this->userProfileCustomization ? $this->userProfileCustomization->cover()->url() : null;
    }

    public function getUserTwitterAttribute($value)
    {
        return presence(ltrim($value, '@'));
    }

    public function getUserLastfmAttribute($value)
    {
        return presence($value);
    }

    public function getUserWebsiteAttribute($value)
    {
        return presence($value);
    }

    public function getUserMsnmAttribute($value)
    {
        return presence($value);
    }

    public function getOsuPlaystyleAttribute($value)
    {
        $value = (int) $value;

        $styles = [];

        foreach (self::PLAYSTYLES as $type => $bit) {
            if (($value & $bit) !== 0) {
                $styles[] = $type;
            }
        }

        if (empty($styles)) {
            return;
        }

        return $styles;
    }

    public function getUserColourAttribute($value)
    {
        if (present($value)) {
            return "#{$value}";
        }
    }

    public function setUserColourAttribute($value)
    {
        // also functions for casting null to string
        $this->attributes['user_colour'] = ltrim($value, '#');
    }

    // return a user's API details

    public function getApiDetails($user = null)
    {
        return $this->api;
    }

    public function getApiKey()
    {
        return $this->api->api_key;
    }

    public function setApiKey($key)
    {
        $this->api->api_key = $key;
        $this->api->save();
    }

    /*
    |--------------------------------------------------------------------------
    | Permission Checker Functions
    |--------------------------------------------------------------------------
    |
    | This checks to see if a user is in a specified group.
    | You should try to be specific.
    |
    */

    public function isQAT()
    {
        return $this->isGroup(UserGroup::GROUPS['qat']);
    }

    public function isAdmin()
    {
        return $this->isGroup(UserGroup::GROUPS['admin']);
    }

    public function isGMT()
    {
        return $this->isGroup(UserGroup::GROUPS['gmt']);
    }

    public function isBNG()
    {
        return $this->isGroup(UserGroup::GROUPS['bng']);
    }

    public function isHax()
    {
        return $this->isGroup(UserGroup::GROUPS['hax']);
    }

    public function isDev()
    {
        return $this->isGroup(UserGroup::GROUPS['dev']);
    }

    public function isMod()
    {
        return $this->isGroup(UserGroup::GROUPS['mod']);
    }

    public function isAlumni()
    {
        return $this->isGroup(UserGroup::GROUPS['alumni']);
    }

    public function isRegistered()
    {
        return $this->isGroup(UserGroup::GROUPS['default']);
    }

    public function hasSupported()
    {
        return $this->osu_subscriptionexpiry !== null;
    }

    public function isSupporter()
    {
        return $this->osu_subscriber === true;
    }

    public function isActive()
    {
        return $this->user_lastvisit > Carbon::now()->subMonth();
    }

    public function isOnline()
    {
        return $this->user_lastvisit > Carbon::now()->subMinutes(config('osu.user.online_window'));
    }

    public function isPrivileged()
    {
        return $this->isAdmin()
            || $this->isDev()
            || $this->isMod()
            || $this->isGMT()
            || $this->isBNG()
            || $this->isQAT();
    }

    public function isBanned()
    {
        return $this->user_type === 1;
    }

    public function isRestricted()
    {
        return $this->isBanned() || $this->user_warnings > 0;
    }

    public function isSilenced()
    {
        if (!array_key_exists(__FUNCTION__, $this->memoized)) {
            if ($this->isRestricted()) {
                return true;
            }

            $lastBan = $this->banHistories()->bans()->first();

            $this->memoized[__FUNCTION__] = $lastBan !== null &&
                $lastBan->period !== 0 &&
                $lastBan->endTime()->isFuture();
        }

        return $this->memoized[__FUNCTION__];
    }

    public function groupIds()
    {
        if (!array_key_exists(__FUNCTION__, $this->memoized)) {
            if (isset($this->relations['userGroups'])) {
                $this->memoized[__FUNCTION__] = $this->userGroups->pluck('group_id');
            } else {
                $this->memoized[__FUNCTION__] = model_pluck($this->userGroups(), 'group_id');
            }
        }

        return $this->memoized[__FUNCTION__];
    }

    // check if a user is in a specific group, by ID
    public function isGroup($group)
    {
        return in_array($group, $this->groupIds(), true);
    }

    /*
    |--------------------------------------------------------------------------
    | Entity relationship definitions
    |--------------------------------------------------------------------------
    |
    | These let you do magic. Example:
    | foreach ($user->mods as $mod) {
    |     $response[] = $mod->toArray();
    | }
    | return $response;
    */

    public function userGroups()
    {
        return $this->hasMany(UserGroup::class, 'user_id');
    }

    public function beatmapDiscussionVotes()
    {
        return $this->hasMany(BeatmapDiscussionVote::class, 'user_id');
    }

    public function beatmapsets()
    {
        return $this->hasMany(Beatmapset::class, 'user_id');
    }

    public function beatmaps()
    {
        return $this->hasManyThrough(Beatmap::class, Beatmapset::class, 'user_id');
    }

    public function favourites()
    {
        return $this->hasMany(FavouriteBeatmapset::class, 'user_id');
    }

    public function favouriteBeatmapsets()
    {
        return Beatmapset::whereIn('beatmapset_id', $this->favourites()->select('beatmapset_id')->get());
    }

    public function beatmapsetNominations()
    {
        return $this->hasMany(BeatmapsetEvent::class, 'user_id')->where('type', BeatmapsetEvent::NOMINATE);
    }

    public function beatmapsetNominationsToday()
    {
        return $this->beatmapsetNominations()->where('created_at', '>', Carbon::now()->subDay())->count();
    }

    public function beatmapPlaycounts()
    {
        return $this->hasMany(BeatmapPlaycount::class, 'user_id');
    }

    public function apiKey()
    {
        return $this->hasOne(ApiKey::class, 'user_id');
    }

    public function storeAddresses()
    {
        return $this->hasMany(Store\Address::class, 'user_id');
    }

    public function rank()
    {
        return $this->belongsTo(Rank::class, 'user_rank');
    }

    public function rankHistories()
    {
        return $this->hasMany(RankHistory::class, 'user_id');
    }

    public function country()
    {
        return $this->belongsTo(Country::class, 'country_acronym');
    }

    public function statisticsOsu()
    {
        return $this->statistics('osu', true);
    }

    public function statisticsFruits()
    {
        return $this->statistics('fruits', true);
    }

    public function statisticsMania()
    {
        return $this->statistics('mania', true);
    }

    public function statisticsTaiko()
    {
        return $this->statistics('taiko', true);
    }

    public function statistics($mode, $returnQuery = false)
    {
        if (!in_array($mode, array_keys(Beatmap::MODES), true)) {
            return;
        }

        $mode = studly_case($mode);

        if ($returnQuery === true) {
            return $this->hasOne("App\Models\UserStatistics\\{$mode}", 'user_id');
        } else {
            $relation = "statistics{$mode}";

            return $this->$relation;
        }
    }

    public function scoresOsu()
    {
        return $this->scores('osu', true);
    }

    public function scoresFruits()
    {
        return $this->scores('fruits', true);
    }

    public function scoresMania()
    {
        return $this->scores('mania', true);
    }

    public function scoresTaiko()
    {
        return $this->scores('taiko', true);
    }

    public function scores($mode, $returnQuery = false)
    {
        if (!in_array($mode, array_keys(Beatmap::MODES), true)) {
            return;
        }

        $mode = studly_case($mode);

        if ($returnQuery === true) {
            return $this->hasMany("App\Models\Score\\{$mode}", 'user_id')->default();
        } else {
            $relation = "scores{$mode}";

            return $this->$relation;
        }
    }

    public function scoresFirstOsu()
    {
        return $this->scoresFirst('osu', true);
    }

    public function scoresFirstFruits()
    {
        return $this->scoresFirst('fruits', true);
    }

    public function scoresFirstMania()
    {
        return $this->scoresFirst('mania', true);
    }

    public function scoresFirstTaiko()
    {
        return $this->scoresFirst('taiko', true);
    }

    public function scoresFirst($mode, $returnQuery = false)
    {
        if (!in_array($mode, array_keys(Beatmap::MODES), true)) {
            return;
        }

        $casedMode = studly_case($mode);

        if ($returnQuery === true) {
            $suffix = $mode === 'osu' ? '' : "_{$mode}";

            return $this->belongsToMany("App\Models\Score\Best\\{$casedMode}", "osu_leaders{$suffix}", 'user_id', 'score_id');
        } else {
            $relation = "scoresFirst{$casedMode}";

            return $this->$relation;
        }
    }

    public function scoresBestOsu()
    {
        return $this->scoresBest('osu', true);
    }

    public function scoresBestFruits()
    {
        return $this->scoresBest('fruits', true);
    }

    public function scoresBestMania()
    {
        return $this->scoresBest('mania', true);
    }

    public function scoresBestTaiko()
    {
        return $this->scoresBest('taiko', true);
    }

    public function scoresBest($mode, $returnQuery = false)
    {
        if (!in_array($mode, array_keys(Beatmap::MODES), true)) {
            return;
        }

        $mode = studly_case($mode);

        if ($returnQuery === true) {
            return $this->hasMany("App\Models\Score\Best\\{$mode}", 'user_id')->default();
        } else {
            $relation = "scoresBest{$mode}";

            return $this->$relation;
        }
    }

    public function userProfileCustomization()
    {
        return $this->hasOne(UserProfileCustomization::class, 'user_id');
    }

    public function banHistories()
    {
        return $this->hasMany(UserBanHistory::class, 'user_id');
    }

    public function userPage()
    {
        return $this->belongsTo(Forum\Post::class, 'userpage_post_id');
    }

    public function userAchievements()
    {
        return $this->hasMany(UserAchievement::class, 'user_id');
    }

    public function usernameChangeHistory()
    {
        return $this->hasMany(UsernameChangeHistory::class, 'user_id');
    }

    public function relations()
    {
        return $this->hasMany(UserRelation::class, 'user_id');
    }

    public function friends()
    {
        // 'cuz hasManyThrough is derp

        return self::whereIn('user_id', $this->relations()->friends()->pluck('zebra_id'));
    }

    public function uncachedFollowerCount()
    {
        return UserRelation::where('zebra_id', $this->user_id)->where('friend', 1)->count();
    }

    public function cacheFollowerCount()
    {
        $count = $this->uncachedFollowerCount();

        Cache::put(
            self::CACHING['follower_count']['key'].':'.$this->user_id,
            $count,
            self::CACHING['follower_count']['duration']
        );

        return $count;
    }

    public function followerCount()
    {
        return get_int(Cache::get(self::CACHING['follower_count']['key'].':'.$this->user_id)) ?? $this->cacheFollowerCount();
    }

    public function foes()
    {
        return $this->relations()->where('foe', true);
    }

    public function events()
    {
        return $this->hasMany(Event::class, 'user_id');
    }

    public function beatmapsetRatings()
    {
        return $this->hasMany(BeatmapsetUserRating::class, 'user_id');
    }

    public function givenKudosu()
    {
        return $this->hasMany(KudosuHistory::class, 'giver_id');
    }

    public function receivedKudosu()
    {
        return $this->hasMany(KudosuHistory::class, 'receiver_id');
    }

    public function supports()
    {
        return $this->hasMany(UserDonation::class, 'target_user_id');
    }

    public function givenSupports()
    {
        return $this->hasMany(UserDonation::class, 'user_id');
    }

    public function forumPosts()
    {
        return $this->hasMany(Forum\Post::class, 'poster_id');
    }

    public function changelogs()
    {
        return $this->hasMany(Changelog::class, 'user_id');
    }

    public function getPlaymodeAttribute($value)
    {
        return Beatmap::modeStr($this->osu_playmode);
    }

    public function setPlaymodeAttribute($value)
    {
        $this->osu_playmode = Beatmap::modeInt($attribute);
    }

    public function hasFavourited($beatmapset)
    {
        return $this->favourites->contains('beatmapset_id', $beatmapset->getKey());
    }

    public function flags()
    {
        if (!array_key_exists(__FUNCTION__, $this->memoized)) {
            $flags = [];

            if ($this->country_acronym !== null) {
                $flags['country'] = [$this->country_acronym, $this->country->name];
            }

            $this->memoized[__FUNCTION__] = $flags;
        }

        return $this->memoized[__FUNCTION__];
    }

    public function title()
    {
        if ($this->user_rank !== 0 && $this->user_rank !== null) {
            return $this->rank->rank_title ?? null;
        }
    }

    public function hasProfile()
    {
        return
            $this->user_id !== null
            && !$this->isRestricted()
            && $this->group_id !== 6; // bots
    }

    public function countryName()
    {
        if (!isset($this->flags()['country'])) {
            return;
        }

        return $this->flags()['country'][1];
    }

    public function updatePage($text)
    {
        if ($this->userPage === null) {
            DB::transaction(function () use ($text) {
                $topic = Forum\Topic::createNew(
                    Forum\Forum::find(config('osu.user.user_page_forum_id')),
                    [
                        'title' => "{$this->username}'s user page",
                        'user' => $this,
                        'body' => $text,
                    ]
                );

                $this->update(['userpage_post_id' => $topic->topic_first_post_id]);
            });
        } else {
            $this->userPage->edit($text, $this);
        }

        return $this->fresh();
    }

    public function notificationCount()
    {
        return $this->user_unread_privmsg;
    }

    public function defaultJson()
    {
        return json_item($this, 'User', ['disqus_auth', 'friends']);
    }

    public function supportLength()
    {
        if (!array_key_exists(__FUNCTION__, $this->memoized)) {
            $supportLength = 0;

            foreach ($this->supports as $support) {
                if ($support->cancel === true) {
                    $supportLength -= $support->length;
                } else {
                    $supportLength += $support->length;
                }
            }

            $this->memoized[__FUNCTION__] = $supportLength;
        }

        return $this->memoized[__FUNCTION__];
    }

    public function supportLevel()
    {
        if ($this->osu_subscriber === false) {
            return 0;
        }

        $length = $this->supportLength();

        if ($length < 12) {
            return 1;
        }

        if ($length < 5 * 12) {
            return 2;
        }

        return 3;
    }

    public function refreshForumCache($forum = null, $postsChangeCount = 0)
    {
        if ($forum !== null) {
            if (Forum\Authorize::increasesPostsCount($this, $forum) !== true) {
                $postsChangeCount = 0;
            }

            // In case user_posts is 0 and $postsChangeCount is -1.
            $newPostsCount = DB::raw("GREATEST(CAST(user_posts AS SIGNED) + {$postsChangeCount}, 0)");
        } else {
            $newPostsCount = $this->forumPosts()->whereIn('forum_id', Forum\Authorize::postsCountedForums($this))->count();
        }

        $lastPost = $this->forumPosts()->last()->select('post_time')->first();

        // FIXME: not null column, hence default 0. Change column to allow null
        $lastPostTime = $lastPost !== null ? $lastPost->post_time : 0;

        return $this->update([
            'user_posts' => $newPostsCount,
            'user_lastpost_time' => $lastPostTime,
        ]);
    }

    public function receiveMessage(User $sender, $body, $isAction = false)
    {
        $message = new PrivateMessage();
        $message->user_id = $sender->user_id;
        $message->target_id = $this->user_id;
        $message->content = $body;
        $message->is_action = $isAction;
        $message->save();

        return $message->fresh();
    }

    public function scopeDefault($query)
    {
        return $query->where([
            'user_warnings' => 0,
            'user_type' => 0,
        ]);
    }

    public function scopeOnline($query)
    {
        return $query->whereRaw('user_lastvisit > UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL '.config('osu.user.online_window').' MINUTE))');
    }

    public function checkPassword($password)
    {
        return Hash::check($password, $this->getAuthPassword());
    }

    public function validatePasswordConfirmation()
    {
        $this->validatePasswordConfirmation = true;

        return $this;
    }

    public function setPasswordConfirmationAttribute($value)
    {
        $this->passwordConfirmation = $value;
    }

    public function setPasswordAttribute($value)
    {
        // actual user_password assignment is after validation
        $this->password = $value;
    }

    public function validateCurrentPassword()
    {
        $this->validateCurrentPassword = true;

        return $this;
    }

    public function setCurrentPasswordAttribute($value)
    {
        $this->currentPassword = $value;
    }

    public function validateEmailConfirmation()
    {
        $this->validateEmailConfirmation = true;

        return $this;
    }

    public function setUserEmailConfirmationAttribute($value)
    {
        $this->emailConfirmation = $value;
    }

    public static function attemptLogin($user, $password, $ip = null)
    {
        $ip = $ip ?? Request::getClientIp() ?? '0.0.0.0';

        if (LoginAttempt::isLocked($ip)) {
            return trans('users.login.locked_ip');
        }

        $validAuth = $user === null
            ? false
            : $user->checkPassword($password);

        if (!$validAuth) {
            LoginAttempt::failedAttempt($ip, $user);

            return trans('users.login.failed');
        }
    }

    public static function findForLogin($username)
    {
        return static::where('username', $username)
            ->orWhere('user_email', '=', strtolower($username))
            ->first();
    }

    public static function findForPassport($username)
    {
        return static::findForLogin($username);
    }

    public function validateForPassportPasswordGrant($password)
    {
        return static::attemptLogin($this, $password) === null;
    }

    public function profileCustomization()
    {
        if (!array_key_exists(__FUNCTION__, $this->memoized)) {
            try {
                $this->memoized[__FUNCTION__] = $this
                    ->userProfileCustomization()
                    ->firstOrCreate([]);
            } catch (Exception $ex) {
                if (is_sql_unique_exception($ex)) {
                    // retry on duplicate
                    return $this->profileCustomization();
                }

                throw $ex;
            }
        }

        return $this->memoized[__FUNCTION__];
    }

    public function profileBeatmapsetsRankedAndApproved()
    {
        return $this->beatmapsets()
            ->rankedOrApproved()
            ->active()
            ->with('beatmaps');
    }

    public function profileBeatmapsetsFavourite()
    {
        return $this->favouriteBeatmapsets()
            ->with('beatmaps');
    }

    public function isValid()
    {
        $this->validationErrors()->reset();

        if ($this->isDirty('username')) {
            $errors = static::validateUsername($this->username, $this->getOriginal('username'));

            if (count($errors) > 0) {
                foreach ($errors as $error) {
                    $this->validationErrors()->addTranslated('username', $error);
                }
            }
        }

        if ($this->validateCurrentPassword) {
            if (!$this->checkPassword($this->currentPassword)) {
                $this->validationErrors()->add('current_password', '.wrong_current_password');
            }
        }

        if ($this->validatePasswordConfirmation) {
            if ($this->password !== $this->passwordConfirmation) {
                $this->validationErrors()->add('password_confirmation', '.wrong_password_confirmation');
            }
        }

        if (present($this->password)) {
            if (strpos(strtolower($this->password), strtolower($this->username)) !== false) {
                $this->validationErrors()->add('password', '.contains_username');
            }

            if (strlen($this->password) < 8) {
                $this->validationErrors()->add('password', '.too_short');
            }

            if (WeakPassword::check($this->password)) {
                $this->validationErrors()->add('password', '.weak');
            }

            if ($this->validationErrors()->isEmpty()) {
                $this->user_password = Hash::make($this->password);
            }
        }

        if ($this->validateEmailConfirmation) {
            if ($this->user_email !== $this->emailConfirmation) {
                $this->validationErrors()->add('user_email_confirmation', '.wrong_email_confirmation');
            }
        }

        if (present($this->user_email)) {
            if (strpos($this->user_email, '@') === false) {
                $this->validationErrors()->add('user_email', '.invalid_email');
            }

            if (static::where('user_id', '<>', $this->getKey())->where('user_email', '=', $this->user_email)->exists()) {
                $this->validationErrors()->add('user_email', '.email_already_used');
            }
        }

        return $this->validationErrors()->isEmpty();
    }

    public function validationErrorsTranslationPrefix()
    {
        return 'user';
    }
}
