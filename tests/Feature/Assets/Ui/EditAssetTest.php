<?php

namespace Tests\Feature\Assets\Ui;

use App\Events\CheckoutableCheckedIn;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Location;
use App\Models\StatusLabel;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class EditAssetTest extends TestCase
{

    public function testPermissionRequiredToViewAsset()
    {
        $asset = Asset::factory()->create();
        $this->actingAs(User::factory()->create())
            ->get(route('hardware.edit', $asset))
            ->assertForbidden();
    }

    public function testPageCanBeAccessed(): void
    {
        $asset = Asset::factory()->create();
        $user = User::factory()->editAssets()->create();
        $response = $this->actingAs($user)->get(route('hardware.edit', $asset->id));
        $response->assertStatus(200);
    }

    public function testAssetEditPostIsRedirectedIfRedirectSelectionIsIndex()
    {
        $asset = Asset::factory()->assignedToUser()->create();

        $this->actingAs(User::factory()->viewAssets()->editAssets()->create())
            ->from(route('hardware.edit', $asset))
            ->put(route('hardware.update', $asset),
                [
                    'redirect_option' => 'index',
                    'name' => 'New name',
                    'asset_tags' => 'New Asset Tag',
                    'status_id' => StatusLabel::factory()->create()->id,
                    'model_id' => AssetModel::factory()->create()->id,
                ])
            ->assertStatus(302)
            ->assertRedirect(route('hardware.index'));
        $this->assertDatabaseHas('assets', ['asset_tag' => 'New Asset Tag']);
    }
    public function testAssetEditPostIsRedirectedIfRedirectSelectionIsItem()
    {
        $asset = Asset::factory()->create();

        $this->actingAs(User::factory()->viewAssets()->editAssets()->create())
            ->from(route('hardware.edit', $asset))
            ->put(route('hardware.update', $asset), [
                'redirect_option' => 'item',
                'name' => 'New name',
                'asset_tags' => 'New Asset Tag',
                'status_id' => StatusLabel::factory()->create()->id,
                'model_id' => AssetModel::factory()->create()->id,
            ])
            ->assertStatus(302)
            ->assertRedirect(route('hardware.show', ['hardware' => $asset->id]));

        $this->assertDatabaseHas('assets', ['asset_tag' => 'New Asset Tag']);
    }

    public function testNewCheckinIsLoggedIfStatusChangedToUndeployable()
    {
        Event::fake([CheckoutableCheckedIn::class]);

        $user = User::factory()->create();
        $location = Location::factory()->create();
        $deployable_status = Statuslabel::factory()->rtd()->create();
        $achived_status = Statuslabel::factory()->archived()->create();

        $asset = Asset::factory()->assignedToUser($user)->create(['status_id' => $deployable_status->id]);

        $this->assertTrue($asset->assignedTo->is($user));

        $currentTimestamp = now();

        $this->actingAs(User::factory()->viewAssets()->editAssets()->create())
            ->post(
                route('hardware.update', ['hardware' => $asset->id]),
                [
                    'status_id' => $achived_status->id,
                ],
            );

        $asset->refresh();
        \Log::error('AssignedTo: '.$asset->refresh()->assignedTo);
        \Log::error('Assigned_to: '.$asset->refresh()->assigned_to);
        \Log::error('Assigned_type: '.$asset->refresh()->assigned_type);
        $this->assertNull($asset->refresh()->assignedTo);
        $this->assertNull($asset->refresh()->assigned_type);
        $this->assertEquals($achived_status->id, $asset->refresh()->status_id);

        Event::assertDispatched(function (CheckoutableCheckedIn $event) use ($currentTimestamp) {
            // this could be better mocked but is ok for now.
            return Carbon::parse($event->action_date)->diffInSeconds($currentTimestamp) < 2;
        }, 1);
    }

}
