<?php
/**
 * Model factory for cryptany service
 * PHP Version 7
 *
 * @category DB
 * @package  Database\ModelFactory
 * @author   Eugene Rupakov <eugene.rupakov@gmail.com>
 * @license  Apache Common License 2.0
 * @link     http://cgw.cryptany.io
 */
/*
|--------------------------------------------------------------------------
| Model Factories
|--------------------------------------------------------------------------
|
| Here you may define all of your model factories. Model factories give
| you a convenient way to create models for testing and seeding your
| database. Just tell the factory how a default model should look.
|
*/

$factory->define(App\User::class, function (Faker\Generator $faker) {
    return [
        'first_name' => $faker->firstName,
        'family_name' => $faker->lastName,
        'email' => $faker->unique()->safeEmail,
        'password' => $faker->password,
        'pin' => '1234'
    ];
});

$factory->define(App\APIUser::class, function (Faker\Generator $faker) {
    return [
        'username' => 'android',
        'appToken' => str_random(10),
        'expiryDate' => '2018-10-01',
        'description' => 'test apiuser'
    ];
});