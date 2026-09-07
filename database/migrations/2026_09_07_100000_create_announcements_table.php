<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Things the company wants everybody to know.
 *
 * There was nowhere to put them. A change of payroll date, a client visit, an
 * office closure — all of it travelled by group chat, which nobody can search
 * later and new hires never see at all. A notice that only exists in a chat
 * thread stops existing the moment the thread scrolls.
 *
 * Dated rather than simply posted, because most notices are about a day. An
 * exhibit runs 8 to 13 September and should be on the dashboard for exactly
 * those days, not until somebody remembers to delete it. The dates are what let
 * the board answer "what is happening today" without anybody tidying up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();

            $table->string('title');
            $table->text('body');

            // News, an event, a reminder, or something urgent. Changes how it
            // is coloured and sorted, nothing else.
            $table->string('kind')->default('news');

            /*
             * The window it is shown in.
             *
             * starts_on is when it appears — a notice can be written today and
             * dated for Monday. ends_on is nullable and means "until it is
             * taken down", which is right for a standing notice like a change
             * of payroll date.
             */
            $table->date('starts_on');
            $table->date('ends_on')->nullable();

            // Holds it at the top of the board regardless of date.
            $table->boolean('is_pinned')->default(false);

            /*
             * Null means a draft.
             *
             * Kept apart from starts_on so a notice can be written in advance
             * and still be checked before anybody sees it. Dating something for
             * next week is not the same as agreeing it is ready to send.
             */
            $table->timestamp('published_at')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The board's only query: published, and covering today.
            $table->index(['published_at', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
