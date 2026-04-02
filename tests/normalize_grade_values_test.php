<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Tests for the normalizeGradeValues() method in grade_report_forecast.
 *
 * Covers the MD-998 fix: division-by-zero guard when grademax == 0 (or
 * grademax == grademin for non-scale items).
 *
 * @package    gradereport_forecast
 * @category   test
 * @group      gradereport_forecast
 * @covers     grade_report_forecast
 * @copyright  2026 Louisiana State University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/lib.php');

/**
 * Unit tests for normalizeGradeValues() private method (MD-998 regression guard).
 *
 * The method is private; we expose it via ReflectionMethod following the Moodle
 * convention documented in phpunit_util::call_internal_method().
 *
 * grade_item objects are partially mocked so that get_parent_category() can
 * return a controlled object without hitting the database.
 */
class gradereport_forecast_normalize_grade_values_test extends advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Return a grade_report_forecast instance with the constructor bypassed.
     * This lets us exercise the private method in pure-logic isolation.
     */
    private function make_report_stub(): grade_report_forecast {
        return $this->getMockBuilder(grade_report_forecast::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }

    /**
     * Return a partial grade_item mock where get_parent_category() returns
     * an object with the given aggregation constant.
     *
     * @param float $grademax
     * @param float $grademin
     * @param int   $gradetype  GRADE_TYPE_VALUE | GRADE_TYPE_SCALE
     * @param int   $aggregation  Parent category aggregation (e.g. GRADE_AGGREGATE_SUM)
     */
    private function make_grade_item_mock(
        float $grademax,
        float $grademin,
        int $gradetype = GRADE_TYPE_VALUE,
        int $aggregation = GRADE_AGGREGATE_WEIGHTED_MEAN2
    ): grade_item {
        $parentcat = new stdClass();
        $parentcat->aggregation = $aggregation;

        $mock = $this->getMockBuilder(grade_item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_parent_category'])
            ->getMock();

        $mock->grademax  = $grademax;
        $mock->grademin  = $grademin;
        $mock->gradetype = $gradetype;

        $mock->method('get_parent_category')->willReturn($parentcat);

        return $mock;
    }

    /**
     * Invoke the private normalizeGradeValues() method via reflection.
     *
     * @param grade_report_forecast $report
     * @param array                 $gradeItems  (passed by reference inside the method)
     * @param array                 $gradeValues
     * @return array  The returned normalised values.
     */
    private function call_normalize(
        grade_report_forecast $report,
        array &$gradeItems,
        array $gradeValues
    ): array {
        $method = new ReflectionMethod(grade_report_forecast::class, 'normalizeGradeValues');
        $method->setAccessible(true);
        return $method->invoke($report, $gradeItems, $gradeValues);
    }

    // -----------------------------------------------------------------------
    // Test cases
    // -----------------------------------------------------------------------

    /**
     * Normal case: non-scale item, grademax=100, grademin=0, value=50.
     * Expected normalised value = 50 / (100 - 0) = 0.5.
     *
     * @covers grade_report_forecast::normalizeGradeValues
     */
    public function test_normal_value_item_normalizes_correctly(): void {
        $report = $this->make_report_stub();

        $item = $this->make_grade_item_mock(100, 0, GRADE_TYPE_VALUE);
        $gradeItems  = [1 => $item];
        $gradeValues = [1 => 50];

        $result = $this->call_normalize($report, $gradeItems, $gradeValues);

        $this->assertArrayHasKey(1, $result, 'Valid item must be present in normalised output.');
        $this->assertSame(0.5, $result[1],   'Normalised value must be 50/100 = 0.5.');
        $this->assertArrayHasKey(1, $gradeItems, 'Valid item must not be removed from gradeItems.');
    }

    /**
     * Zero-denominator case: non-scale item, grademax=0, grademin=0.
     * Must be silently skipped — no DivisionByZeroError and no output entry.
     *
     * @covers grade_report_forecast::normalizeGradeValues
     */
    public function test_zero_denominator_item_is_skipped_without_exception(): void {
        $report = $this->make_report_stub();

        $item = $this->make_grade_item_mock(0, 0, GRADE_TYPE_VALUE);
        $gradeItems  = [2 => $item];
        $gradeValues = [2 => 0];

        $result = $this->call_normalize($report, $gradeItems, $gradeValues);

        $this->assertArrayNotHasKey(2, $result,     'Zero-denominator item must not appear in normalised output.');
        $this->assertArrayNotHasKey(2, $gradeItems, 'Zero-denominator item must be unset from gradeItems.');
    }

    /**
     * Scale item with SUM aggregation and grademax=0.
     * Must be skipped without any exception.
     *
     * @covers grade_report_forecast::normalizeGradeValues
     */
    public function test_scale_item_sum_aggregation_zero_grademax_is_skipped(): void {
        $report = $this->make_report_stub();

        $item = $this->make_grade_item_mock(0, 0, GRADE_TYPE_SCALE, GRADE_AGGREGATE_SUM);
        $gradeItems  = [3 => $item];
        $gradeValues = [3 => 1];

        $result = $this->call_normalize($report, $gradeItems, $gradeValues);

        $this->assertArrayNotHasKey(3, $result,     'Scale/SUM item with grademax=0 must not appear in output.');
        $this->assertArrayNotHasKey(3, $gradeItems, 'Scale/SUM item with grademax=0 must be removed from gradeItems.');
    }

    /**
     * Scale item with non-SUM aggregation, value > 1, and grademax=0.
     * Must be skipped without any exception.
     *
     * @covers grade_report_forecast::normalizeGradeValues
     */
    public function test_scale_item_non_sum_value_gt1_zero_grademax_is_skipped(): void {
        $report = $this->make_report_stub();

        $item = $this->make_grade_item_mock(0, 0, GRADE_TYPE_SCALE, GRADE_AGGREGATE_WEIGHTED_MEAN2);
        $gradeItems  = [4 => $item];
        $gradeValues = [4 => 5];   // value > 1 triggers the grademax division branch

        $result = $this->call_normalize($report, $gradeItems, $gradeValues);

        $this->assertArrayNotHasKey(4, $result,     'Scale/non-SUM item (value>1, grademax=0) must not appear in output.');
        $this->assertArrayNotHasKey(4, $gradeItems, 'Scale/non-SUM item (value>1, grademax=0) must be removed from gradeItems.');
    }

    /**
     * Mixed items: one zero-denominator and one valid (grademax=100).
     * Only the valid item must appear in the normalised output; the
     * zero-denominator item must be excluded.
     *
     * @covers grade_report_forecast::normalizeGradeValues
     */
    public function test_mixed_items_only_valid_items_are_normalised(): void {
        $report = $this->make_report_stub();

        $validItem   = $this->make_grade_item_mock(100, 0, GRADE_TYPE_VALUE);
        $invalidItem = $this->make_grade_item_mock(0,   0, GRADE_TYPE_VALUE);

        $gradeItems  = [10 => $validItem, 11 => $invalidItem];
        $gradeValues = [10 => 75, 11 => 0];

        $result = $this->call_normalize($report, $gradeItems, $gradeValues);

        $this->assertArrayHasKey(10, $result,    'Valid item must be present in normalised output.');
        $this->assertSame(0.75, $result[10],     'Valid item must be normalised to 75/100 = 0.75.');
        $this->assertArrayNotHasKey(11, $result, 'Zero-denominator item must be absent from normalised output.');
        $this->assertArrayHasKey(10, $gradeItems,    'Valid item must remain in gradeItems.');
        $this->assertArrayNotHasKey(11, $gradeItems, 'Zero-denominator item must be removed from gradeItems.');
    }

    /**
     * Edge case: grademax equals grademin, both non-zero (e.g. max=5, min=5).
     * Denominator (grademax - grademin) is zero; item must be skipped without exception.
     *
     * @covers grade_report_forecast::normalizeGradeValues
     */
    public function test_grademax_equals_nonzero_grademin_is_skipped(): void {
        $report = $this->make_report_stub();

        $item = $this->make_grade_item_mock(5, 5, GRADE_TYPE_VALUE);
        $gradeItems  = [5 => $item];
        $gradeValues = [5 => 5];

        $result = $this->call_normalize($report, $gradeItems, $gradeValues);

        $this->assertArrayNotHasKey(5, $result,     'Item with grademax==grademin must not appear in output.');
        $this->assertArrayNotHasKey(5, $gradeItems, 'Item with grademax==grademin must be removed from gradeItems.');
    }
}
