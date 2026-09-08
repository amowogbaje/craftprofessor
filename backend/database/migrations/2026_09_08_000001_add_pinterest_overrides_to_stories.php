<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-STORY Pinterest overrides — deliberately on `stories`, not `series`:
 * a series can span very different-feeling stories that don't all belong
 * on the same board or want the same posting cadence, so the override
 * lives at the level the user actually asked for.
 *
 * Both are optional overrides, not replacements for the account-level
 * setup on PinterestBoardsPage (SocialAccount::board_posting_mode /
 * preferredBoards — see PinterestBoardSelectionService):
 *  - pinterest_board_id: when set, every pin from this story goes to this
 *    one board, skipping the account's fixed/dynamic board logic
 *    entirely. When null (the default for every existing and new story),
 *    board selection falls back to whatever was chosen on the socials
 *    Pinterest setup page exactly as it already worked before this
 *    column existed.
 *  - pinterest_daily_pin_limit: when set, this story gets its OWN daily
 *    pin budget, counted independently of any other story's — see
 *    PostPinterestPins for the per-(user, story) accounting this drives.
 *    When null, this story shares in the account-wide default
 *    (services.pinterest.max_pins_per_user_per_day) the same way every
 *    story did before this column existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->foreignId('pinterest_board_id')->nullable()->after('series_id')
                ->constrained('pinterest_boards')->nullOnDelete();
            $table->unsignedSmallInteger('pinterest_daily_pin_limit')->nullable()->after('pinterest_board_id');
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pinterest_board_id');
            $table->dropColumn('pinterest_daily_pin_limit');
        });
    }
};
