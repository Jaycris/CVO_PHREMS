<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A text sent to the whole company, on its own.
 *
 * Separate from announcements because it is a separate act. A notice goes on
 * the board and stays there to be re-read; this interrupts fifty-two people
 * wherever they are, costs a credit each, and then exists nowhere. "Office
 * closed, do not come in" needs to reach phones in the next two minutes and has
 * no business becoming a dashboard item.
 *
 * Recorded because of that last part. Money left the account and everybody's
 * phone buzzed, so there has to be a row saying what was sent, by whom, and how
 * many of them it actually reached — otherwise the only evidence is on fifty-two
 * handsets and a gateway bill nobody can reconcile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_broadcasts', function (Blueprint $table) {
            $table->id();

            // Stored as it was sent — already shortened and character-converted
            // by the gateway, so this is what the handsets actually showed.
            $table->text('message');

            /*
             * Two counts, because they differ and the difference is the point.
             * attempted is everyone with a usable mobile on file; sent is how
             * many the gateway accepted. A gap between them is a delivery
             * problem worth seeing rather than a silent shortfall.
             */
            $table->unsignedInteger('recipients_attempted')->default(0);
            $table->unsignedInteger('recipients_sent')->default(0);

            // Staff with no number, or a landline. Named so nobody has to work
            // out why 52 employees produced 48 texts.
            $table->unsignedInteger('recipients_skipped')->default(0);

            /*
             * Who it was aimed at. Null means everybody currently employed.
             *
             * Stored rather than derived, because "everybody" moves: somebody
             * who joins next month was not on this text and somebody who leaves
             * still was. The list is the only honest record of who a message
             * was actually addressed to.
             */
            $table->json('employee_ids')->nullable();

            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_broadcasts');
    }
};
