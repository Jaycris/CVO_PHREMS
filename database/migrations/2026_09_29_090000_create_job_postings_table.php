<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A role the company is hiring for, written once in PHREMS and shown on the
 * company website's Join Our Team page.
 *
 * HR is the only author. The website reads published rows through a public,
 * read-only endpoint, so nobody maintains the same advert twice and taking a
 * role down is one click here rather than an email to whoever owns the site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_postings', function (Blueprint $table) {
            $table->id();

            // What the website shows.
            $table->string('title');
            // The website's address for this role. Unique and never reused, so
            // a link shared on Facebook keeps pointing at the same advert.
            $table->string('slug')->unique();
            $table->string('summary', 500)->nullable();
            $table->text('description')->nullable();
            $table->text('responsibilities')->nullable();
            $table->text('qualifications')->nullable();

            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employment_type')->nullable();
            $table->string('workplace_type')->nullable();
            $table->string('location')->nullable();
            $table->unsignedSmallInteger('headcount')->default(1);

            // Off by default. A salary on a public advert is a decision, not a
            // side effect of filling the field in.
            $table->decimal('salary_min', 12, 2)->nullable();
            $table->decimal('salary_max', 12, 2)->nullable();
            $table->boolean('salary_visible')->default(false);

            // Where applications go until PHREMS has a form of its own.
            $table->string('apply_email')->nullable();
            $table->string('apply_url')->nullable();

            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->date('closes_on')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_postings');
    }
};
