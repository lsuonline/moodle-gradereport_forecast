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
 * Tests for gradereport_forecast library functions.
 *
 * @package    gradereport_forecast
 * @copyright  2016 Louisiana State University, Chad Mazilly, Robert Russo, Dave Elliott
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/grade/report/forecast/lib.php');

/**
 * Class gradereport_forecast_lib_testcase.
 *
 * @package    gradereport_forecast
 * @copyright  2015 onwards Ankit agarwal <ankit.agrr@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later.
 */
class gradereport_forecast_lib_testcase extends advanced_testcase {

    /**
     * @var stdClass The user.
     */
    private $user;

    /**
     * @var stdClass The course.
     */
    private $course;

    /**
     * @var \core_user\output\myprofile\tree The navigation tree.
     */
    private $tree;

    public function setUp() {
        $this->user = $this->getDataGenerator()->create_user();
        $this->course = $this->getDataGenerator()->create_course();
        $this->tree = new \core_user\output\myprofile\tree();
        $this->resetAfterTest();
    }

    /**
     * Tests the gradereport_forecast_myprofile_navigation() function.
     */
    public function test_gradereport_forecast_myprofile_navigation() {
        $this->setAdminUser();
        $iscurrentuser = false;

        gradereport_forecast_myprofile_navigation($this->tree, $this->user, $iscurrentuser, $this->course);
        $reflector = new ReflectionObject($this->tree);
        $nodes = $reflector->getProperty('nodes');
        $nodes->setAccessible(true);
        $this->assertArrayHasKey('grade', $nodes->getValue($this->tree));
    }

    /**
     * Tests the gradereport_forecast_myprofile_navigation() function for a user
     * without permission to view the grade node.
     */
    public function test_gradereport_forecast_myprofile_navigation_without_permission() {
        $this->setUser($this->user);
        $iscurrentuser = true;

        gradereport_forecast_myprofile_navigation($this->tree, $this->user, $iscurrentuser, $this->course);
        $reflector = new ReflectionObject($this->tree);
        $nodes = $reflector->getProperty('nodes');
        $nodes->setAccessible(true);
        $this->assertArrayNotHasKey('grade', $nodes->getValue($this->tree));
    }
}

/**
 * Tests for the isZeroRangeItem() private helper (MD-998).
 *
 * @package    gradereport_forecast
 * @category   test
 * @group      gradereport_forecast
 * @covers     grade_report_forecast
 * @copyright  2026 Louisiana State University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gradereport_forecast_is_zero_range_item_test extends advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Return a grade_report_forecast stub with constructor bypassed.
     */
    private function make_report_stub(): grade_report_forecast {
        return $this->getMockBuilder(grade_report_forecast::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }

    /**
     * Build a minimal grade_item mock with given grademax/grademin.
     *
     * @param float $grademax
     * @param float $grademin
     * @return grade_item
     */
    private function make_item(float $grademax, float $grademin): grade_item {
        $mock = $this->getMockBuilder(grade_item::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $mock->grademax = $grademax;
        $mock->grademin = $grademin;
        return $mock;
    }

    /**
     * Invoke the private isZeroRangeItem() method via reflection.
     *
     * @param grade_report_forecast $report
     * @param grade_item            $item
     * @return bool
     */
    private function call_is_zero_range(grade_report_forecast $report, grade_item $item): bool {
        $method = new ReflectionMethod(grade_report_forecast::class, 'isZeroRangeItem');
        $method->setAccessible(true);
        return $method->invoke($report, $item);
    }

    /**
     * grademax=0, grademin=0 → zero-range (true).
     *
     * @covers grade_report_forecast::isZeroRangeItem
     */
    public function test_zero_max_zero_min_is_zero_range(): void {
        $report = $this->make_report_stub();
        $item   = $this->make_item(0, 0);
        $this->assertTrue($this->call_is_zero_range($report, $item));
    }

    /**
     * grademax=1, grademin=0 → normal range (false).
     *
     * @covers grade_report_forecast::isZeroRangeItem
     */
    public function test_normal_range_is_not_zero_range(): void {
        $report = $this->make_report_stub();
        $item   = $this->make_item(1, 0);
        $this->assertFalse($this->call_is_zero_range($report, $item));
    }

    /**
     * grademax=-1, grademin=0 → grademax < grademin → zero-range (true).
     *
     * @covers grade_report_forecast::isZeroRangeItem
     */
    public function test_negative_grademax_is_zero_range(): void {
        $report = $this->make_report_stub();
        $item   = $this->make_item(-1, 0);
        $this->assertTrue($this->call_is_zero_range($report, $item));
    }

    /**
     * grademax=10, grademin=10 → grademax == grademin → zero-range (true).
     *
     * @covers grade_report_forecast::isZeroRangeItem
     */
    public function test_equal_nonzero_max_and_min_is_zero_range(): void {
        $report = $this->make_report_stub();
        $item   = $this->make_item(10, 10);
        $this->assertTrue($this->call_is_zero_range($report, $item));
    }
}

/**
 * Tests for the calculateMustMake() guard on empty ungradedGradeItemKey (MD-998).
 *
 * @package    gradereport_forecast
 * @category   test
 * @group      gradereport_forecast
 * @covers     grade_report_forecast
 * @copyright  2026 Louisiana State University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gradereport_forecast_calculate_must_make_test extends advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Return a grade_report_forecast stub with constructor bypassed and
     * ungradedGradeItemKey set to the given value.
     *
     * @param mixed $key
     * @return grade_report_forecast
     */
    private function make_report_with_key($key): grade_report_forecast {
        $report = $this->getMockBuilder(grade_report_forecast::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $prop = new ReflectionProperty(grade_report_forecast::class, 'ungradedGradeItemKey');
        $prop->setAccessible(true);
        $prop->setValue($report, $key);

        return $report;
    }

    /**
     * When ungradedGradeItemKey is null, calculateMustMake() must return [] immediately.
     *
     * @covers grade_report_forecast::calculateMustMake
     */
    public function test_calculate_must_make_returns_empty_when_key_is_null(): void {
        $report = $this->make_report_with_key(null);

        $method = new ReflectionMethod(grade_report_forecast::class, 'calculateMustMake');
        $method->setAccessible(true);
        $result = $method->invoke($report);

        $this->assertSame([], $result, 'calculateMustMake() must return [] when ungradedGradeItemKey is empty.');
    }

    /**
     * When ungradedGradeItemKey is an empty string, calculateMustMake() must return [].
     *
     * @covers grade_report_forecast::calculateMustMake
     */
    public function test_calculate_must_make_returns_empty_when_key_is_empty_string(): void {
        $report = $this->make_report_with_key('');

        $method = new ReflectionMethod(grade_report_forecast::class, 'calculateMustMake');
        $method->setAccessible(true);
        $result = $method->invoke($report);

        $this->assertSame([], $result, 'calculateMustMake() must return [] when ungradedGradeItemKey is empty string.');
    }
}
