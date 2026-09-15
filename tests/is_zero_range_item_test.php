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
 * Tests for the isZeroRangeItem() method in grade_report_forecast.
 *
 * Covers the MD-998 fix: pristine bounds must be used so that items whose
 * grademax/grademin are temporarily mutated by blank_hidden_total_and_adjust_bounds()
 * are not falsely classified as zero-range (and thus wrongly rendered as static labels).
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
 * Unit tests for the isZeroRangeItem() private method (MD-998 regression guard).
 *
 * The method is private; we expose it via ReflectionMethod.
 */
class gradereport_forecast_is_zero_range_item_test extends advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** Build a stub grade_report_forecast without running the constructor. */
    private function make_report_stub(): grade_report_forecast {
        return $this->getMockBuilder(grade_report_forecast::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }

    /**
     * Invoke the private isZeroRangeItem() method.
     *
     * @param grade_report_forecast $report
     * @param grade_item            $item
     * @param float|null            $pristineGrademax
     * @param float|null            $pristineGrademin
     * @return bool
     */
    private function call_is_zero_range(
        grade_report_forecast $report,
        grade_item $item,
        ?float $pristineGrademax = null,
        ?float $pristineGrademin = null
    ): bool {
        $method = new ReflectionMethod(grade_report_forecast::class, 'isZeroRangeItem');
        $method->setAccessible(true);
        return $method->invoke($report, $item, $pristineGrademax, $pristineGrademin);
    }

    /** Build a minimal grade_item mock with the given bounds. */
    private function make_item(float $grademax, float $grademin): grade_item {
        $mock = $this->getMockBuilder(grade_item::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $mock->grademax = $grademax;
        $mock->grademin = $grademin;
        return $mock;
    }

    // -----------------------------------------------------------------------
    // Tests — no pristine bounds (legacy behaviour, unchanged)
    // -----------------------------------------------------------------------

    /**
     * H5P activity: grademax=0, grademin=0 → must be zero-range.
     */
    public function test_grademax_zero_grademin_zero_is_zero_range(): void {
        $report = $this->make_report_stub();
        $item = $this->make_item(0.0, 0.0);

        $this->assertTrue(
            $this->call_is_zero_range($report, $item),
            'Item with grademax=0 grademin=0 must be detected as zero-range.'
        );
    }

    /**
     * Normal item: grademax=100, grademin=0 → must NOT be zero-range.
     */
    public function test_normal_item_is_not_zero_range(): void {
        $report = $this->make_report_stub();
        $item = $this->make_item(100.0, 0.0);

        $this->assertFalse(
            $this->call_is_zero_range($report, $item),
            'Normal item (0-100) must not be zero-range.'
        );
    }

    /**
     * Edge: grademax equals non-zero grademin (e.g. 5=5) → zero-range.
     */
    public function test_grademax_equals_nonzero_grademin_is_zero_range(): void {
        $report = $this->make_report_stub();
        $item = $this->make_item(5.0, 5.0);

        $this->assertTrue(
            $this->call_is_zero_range($report, $item),
            'Item with grademax==grademin (both 5) must be zero-range.'
        );
    }

    // -----------------------------------------------------------------------
    // Tests — pristine bounds (MD-998 regression: adjusted vs pristine)
    // -----------------------------------------------------------------------

    /**
     * Regression for MD-998 Melissa bug:
     *
     * A normal item (pristine 0-100) whose bounds are mutated by
     * blank_hidden_total_and_adjust_bounds() to 0-0 must NOT be detected as
     * zero-range when pristine bounds are supplied.
     *
     * Without the fix, isZeroRangeItem() read the mutated grademax=0/grademin=0
     * and wrongly returned true, causing the item to be rendered as a static label
     * (0.00000 - 100.00000) instead of an editable forecast input.
     */
    public function test_adjusted_bounds_zero_but_pristine_normal_is_not_zero_range(): void {
        $report = $this->make_report_stub();

        // Simulate post-mutation state: item now shows 0-0 due to hidden siblings.
        $item = $this->make_item(0.0, 0.0);

        // But pristine DB values were 0-100 (a normal gradeable item).
        $this->assertFalse(
            $this->call_is_zero_range($report, $item, 100.0, 0.0),
            'Item whose pristine bounds are 0-100 must not be zero-range even if ' .
            'adjusted bounds have been mutated to 0-0.'
        );
    }

    /**
     * A genuine H5P zero-range item (pristine 0-0) must still be detected as
     * zero-range even when pristine bounds are explicitly passed.
     */
    public function test_pristine_zero_range_item_is_still_detected(): void {
        $report = $this->make_report_stub();
        $item = $this->make_item(0.0, 0.0);

        $this->assertTrue(
            $this->call_is_zero_range($report, $item, 0.0, 0.0),
            'Genuine zero-range item must still return true when pristine bounds are 0-0.'
        );
    }

    /**
     * When pristine grademax is null (not supplied), the method falls back to
     * reading from the grade_item object — preserving backward compatibility.
     */
    public function test_null_pristine_bounds_falls_back_to_item_values(): void {
        $report = $this->make_report_stub();

        // Item object has normal bounds.
        $item = $this->make_item(100.0, 0.0);

        $this->assertFalse(
            $this->call_is_zero_range($report, $item, null, null),
            'With null pristine bounds, method must fall back to item values (0-100 = not zero-range).'
        );
    }

    /**
     * Mixed course scenario: course has one H5P item (zero-range, pristine 0-0) and
     * one normal forum item (pristine 0-100 but adjusted to 0-0 by hidden siblings).
     *
     * The H5P item must be zero-range; the forum item must not be.
     * This is the core scenario from Melissa's testrusso bug report.
     */
    public function test_mixed_course_h5p_zero_and_normal_item_adjusted_to_zero(): void {
        $report = $this->make_report_stub();

        // H5P item: pristine AND adjusted bounds are both 0-0.
        $h5p = $this->make_item(0.0, 0.0);
        $this->assertTrue(
            $this->call_is_zero_range($report, $h5p, 0.0, 0.0),
            'H5P item with pristine grademax=0 must be zero-range.'
        );

        // Normal forum item: pristine 0-100, but adjusted to 0-0 by hidden siblings.
        $forum = $this->make_item(0.0, 0.0);   // post-mutation state on the object
        $this->assertFalse(
            $this->call_is_zero_range($report, $forum, 100.0, 0.0),
            'Forum item with pristine 0-100 bounds must NOT be zero-range even if ' .
            'the grade_item object currently shows mutated 0-0 bounds.'
        );
    }
}
