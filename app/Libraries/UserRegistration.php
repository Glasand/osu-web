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

namespace App\Libraries;

use App\Exceptions\ModelNotSavedException;
use App\Models\User;
use App\Models\UserGroup;
use Carbon\Carbon;
use DB;

class UserRegistration
{
    private $user;

    public function __construct($params)
    {
        $this->user = new User(array_merge([
            'username_clean' => $params['username'],
            'user_permissions' => '',
            'user_interests' => '',
            'user_occ' => '',
            'user_sig' => '',
            'user_regdate' => Carbon::now(),
        ], $params));
    }

    public function save()
    {
        try {
            $ok = DB::transaction(function () {
                $this->user->saveOrExplode();

                $ok = $this->user->userGroups()->create([
                    'group_id' => UserGroup::GROUPS['default'],
                ]);

                if ($ok === false) {
                    throw new ModelNotSavedException('failed saving model');
                }

                return true;
            });
        } catch (ModelNotSavedException $_e) {
            $ok = false;
        }

        return $ok;
    }

    public function user()
    {
        return $this->user;
    }
}
