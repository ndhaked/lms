<?php

namespace Tests\Feature\Api;

use App\Actions\Books\AddCopies;
use App\Actions\Books\SaveBook;
use App\Models\Book;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class BooksApiTest extends ApiTestCase
{
    protected Book $book;

    protected array $codes;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-19 10:00:00');   // a Monday
        $this->actingAs($this->owner);
        [$this->book, $this->codes] = $this->inLibrary(function () {
            $book = app(SaveBook::class)(['type' => 'physical', 'title' => 'The Alchemist', 'author' => 'Paulo Coelho', 'isbn' => '9780062315007', 'purchase_price' => '350']);
            $copies = app(AddCopies::class)($book, 2);

            return [$book, $copies->pluck('barcode')->all()];
        });
        $this->app['auth']->forgetGuards();
    }

    public function test_catalogue_search_detail_and_barcode_lookup(): void
    {
        $t = $this->ownerToken();
        $this->as($t)->getJson($this->lib('books?q=alchemist'))->assertOk()->assertJsonPath('data.0.title', 'The Alchemist')->assertJsonPath('data.0.available', 2);
        $this->as($t)->getJson($this->lib("books/{$this->book->id}"))->assertOk()->assertJsonCount(2, 'data.copy_list');
        $this->as($t)->getJson($this->lib('books/lookup?code='.$this->codes[0]))->assertOk()->assertJsonPath('data.book.title', 'The Alchemist')->assertJsonPath('data.on_loan', null);
        $this->as($t)->getJson($this->lib('books/lookup?code=NOPE'))->assertNotFound();
    }

    public function test_issue_return_renew_with_fines_and_reservations(): void
    {
        $s = $this->member();
        $t = $this->ownerToken();

        $this->as($t)->getJson($this->lib("circulation/members/{$s->id}"))->assertOk()->assertJsonPath('data.block_reason', null);
        $issued = $this->as($t)->postJson($this->lib('circulation/issue'), ['student_id' => $s->id, 'barcodes' => [$this->codes[0]]])->assertCreated()->json('data.items.0');
        $this->as($t)->postJson($this->lib('circulation/issue'), ['student_id' => $s->id, 'barcodes' => [$this->codes[0]]])->assertStatus(422);   // already out
        $this->as($t)->getJson($this->lib('loans'))->assertOk()->assertJsonPath('summary.open', 1);
        $this->as($t)->postJson($this->lib("loans/{$issued['id']}/renew"))->assertOk();

        // a second member reserves the title; the other copy is still on the shelf so it is issued directly instead
        $other = $this->member();
        $this->as($t)->postJson($this->lib('reservations'), ['student_id' => $other->id, 'book_id' => $this->book->id])->assertStatus(422);

        // return late: a fine appears and can be collected
        Carbon::setTestNow(Carbon::parse($issued['due_on'])->addDays(20)->setTime(11, 0));
        $t = $this->ownerToken();   // a month later: sign in again
        $ret = $this->as($t)->postJson($this->lib('circulation/return'), ['returns' => [['barcode' => $this->codes[0], 'condition' => 'good']]])->assertOk();
        $fine = $ret->json('data.fines.0.id');
        $this->assertNotNull($fine);
        $this->as($t)->getJson($this->lib('fines'))->assertOk()->assertJsonPath('data.0.id', $fine);
        $this->as($t)->postJson($this->lib("fines/{$fine}/collect"), ['method' => 'cash'])->assertOk()->assertJsonPath('data.status', 'paid');
    }

    public function test_lending_needs_its_permissions(): void
    {
        $this->staff($this->roleName('ACCOUNTANT'), 'acc@alpha.test');
        $acc = $this->login('acc@alpha.test');
        $s = $this->member();
        $this->as($acc)->postJson($this->lib('circulation/issue'), ['student_id' => $s->id, 'barcodes' => [$this->codes[0]]])->assertForbidden();
        $this->as($acc)->getJson($this->lib('fines'))->assertOk();   // accountants may see fines
        $this->as($acc)->postJson($this->lib('stock-takes'), ['name' => 'Oct'])->assertForbidden();
    }

    public function test_stock_take_scan_and_complete(): void
    {
        $t = $this->ownerToken();
        $id = $this->as($t)->postJson($this->lib('stock-takes'), ['name' => 'October count'])->assertCreated()->assertJsonPath('data.expected', 2)->json('data.id');
        $this->as($t)->postJson($this->lib("stock-takes/{$id}/scan"), ['code' => $this->codes[0]])->assertOk()->assertJsonPath('data.result', 'ok');
        $this->as($t)->postJson($this->lib("stock-takes/{$id}/scan"), ['code' => $this->codes[0]])->assertOk()->assertJsonPath('data.result', 'already');
        $this->as($t)->postJson($this->lib("stock-takes/{$id}/scan"), ['code' => 'ZZZ'])->assertOk()->assertJsonPath('data.result', 'unknown');
        $this->as($t)->postJson($this->lib("stock-takes/{$id}/complete"))->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.missing', 1);
    }
}
