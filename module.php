<?php

declare(strict_types=1);

namespace BertKoorNamespace;

use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleDataFixInterface;
use Fisharebest\Webtrees\Module\ModuleDataFixTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\LinkedRecordService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\View;
use Illuminate\Support\Collection;

class BulkDeleteModule extends AbstractModule implements ModuleCustomInterface, ModuleDataFixInterface {

    use ModuleCustomTrait;
    use ModuleDataFixTrait;

    private LinkedRecordService $linked_record_service;

    public function __construct() {
        $this->linked_record_service = new LinkedRecordService();
    }

    public function title(): string
    {
        return 'Bulk delete objects';
    }

    public function description(): string
    {
        return 'This module can delete many objects. Got a backup? Use with caution at your own risk. ';
    }

    public function customModuleVersion(): string
    {
        return '0.1.2';
    }

    public function boot(): void
    {
        View::registerNamespace($this->name(), __DIR__ . '/');
    }

    public function fixOptions(Tree $tree): string
    {
        return view($this->name() . '::bulk-delete-options', [
            'xrefs' => '',
        ]);
    }

    protected function individualsToFix(Tree $tree, array &$params): ?Collection
    {
        $result = $this->individualsToFixQuery($tree, [])
            ->whereIn('i_id', $this->xrefsToFix($params))
            ->pluck('i_id');
        return $result;
    }

    protected function familiesToFix(Tree $tree, array &$params): ?Collection
    {
        $result = $this->familiesToFixQuery($tree, [])
            ->whereIn('f_id', $this->xrefsToFix($params))
            ->pluck('f_id');
        return $result;
    }

    protected function sourcesToFix(Tree $tree, array &$params): ?Collection
    {
        $result = $this->sourcesToFixQuery($tree, [])
            ->whereIn('s_id', $this->xrefsToFix($params))
            ->pluck('s_id');
        return $result;
    }

    protected function notesToFix(Tree $tree, array &$params): ?Collection
    {
        $result = $this->notesToFixQuery($tree, [])
            ->whereIn('o_id', $this->xrefsToFix($params))
            ->pluck('o_id');
        return $result;
    }

    protected function mediaToFix(Tree $tree, array &$params): ?Collection
    {
        $result = $this->mediaToFixQuery($tree, [])
            ->whereIn('m_id', $this->xrefsToFix($params))
            ->pluck('m_id');
        return $result;
    }

    private function xrefsToFix(array &$params): array
    {
        if (array_key_exists('split', $params)) {
            $split = $params['split'];
        } else {
            $split = preg_split("/\W/m", $params['xrefs']);
            $params['split'] = $split;
        }
        return $split;
    }

    public function doesRecordNeedUpdate(GedcomRecord $record, array $params): bool
    {
        return true;
    }

    public function previewUpdate(GedcomRecord $record, array $params): string
    {
        return '<s>' . $record->xref() . ': ' . $record->fullName() . '</s>';
    }

    /**
     * Copied from app/Http/RequestHandlers/DeleteRecord.php
     * @param GedcomRecord $record
     * @param array $params
     * @return void
     */
    public function updateRecord(GedcomRecord $record, array $params): void
    {
        if (!$record->canEdit()) {
            error_log('cannot delete: ' . $record->xref());
        } else {
            error_log('deleting: ' . $record->xref());
            // Delete links to this record
            foreach ($this->linked_record_service->allLinkedRecords($record) as $linker) {
                $old_gedcom = $linker->gedcom();
                $new_gedcom = $this->removeLinks($old_gedcom, $record->xref());
                if ($old_gedcom !== $new_gedcom) {
                    // If we have removed a link from a family to an individual, and it now has only one member and no genealogy facts
                    if (
                        $linker instanceof Family &&
                        preg_match('/\n1 (ANUL|CENS|DIV|DIVF|ENGA|MAR[BCLRS]|RESI|EVEN)/', $new_gedcom, $match) !== 1 &&
                        preg_match_all('/\n1 (HUSB|WIFE|CHIL) @(' . Gedcom::REGEX_XREF . ')@/', $new_gedcom, $match) === 1
                    ) {
                        // Delete the family
                        /* I18N: %s is the name of a family group, e.g. “Husband name + Wife name” */
                        error_log(I18N::translate('The family “%s” has been deleted because it only has one member.', $linker->fullName()));
                        $linker->deleteRecord();
                        // Delete the remaining link to this family
                        $relict = Registry::gedcomRecordFactory()->make($match[2][0], $record->tree());
                        if ($relict instanceof Individual) {
                            $relict_gedcom = $this->removeLinks($relict->gedcom(), $linker->xref());
                            $relict->updateRecord($relict_gedcom, false);
                            /* I18N: %s are names of records, such as sources, repositories or individuals */
                            error_log(I18N::translate('The link from “%1$s” to “%2$s” has been deleted.', sprintf('<a href="%1$s" class="alert-link">%2$s</a>', e($relict->url()), $relict->fullName()), $linker->fullName()));
                        }
                    } else if (
                        // If we have removed the last member from a family
                        $linker instanceof Family &&
                        preg_match_all('/\n1 (HUSB|WIFE|CHIL) @(' . Gedcom::REGEX_XREF . ')@/', $new_gedcom, $match) === 0
                    ) {
                        // Delete the family
                        /* I18N: %s is the name of a family group, e.g. “Husband name + Wife name” */
                        error_log(I18N::translate('The family “%s” has been deleted because the last member has been deleted.', $linker->fullName()));
                        $linker->deleteRecord();
                    } else {
                        // Remove links from $linker to $record
                        /* I18N: %s are names of records, such as sources, repositories or individuals */
                        $linker->updateRecord($new_gedcom, false);
                        error_log(I18N::translate('The link from “%1$s” to “%2$s” has been deleted.', sprintf('<a href="%1$s" class="alert-link">%2$s</a>', e($linker->url()), $linker->fullName()), $record->fullName()));
                    }
                }
            }
            // Delete the record itself
            $record->deleteRecord();
        }
    }

    private function removeLinks(string $gedrec, string $xref): string
    {
        $gedrec = preg_replace('/\n1 ' . Gedcom::REGEX_TAG . ' @' . $xref . '@(\n[2-9].*)*/', '', $gedrec);
        $gedrec = preg_replace('/\n2 ' . Gedcom::REGEX_TAG . ' @' . $xref . '@(\n[3-9].*)*/', '', $gedrec);
        $gedrec = preg_replace('/\n3 ' . Gedcom::REGEX_TAG . ' @' . $xref . '@(\n[4-9].*)*/', '', $gedrec);
        $gedrec = preg_replace('/\n4 ' . Gedcom::REGEX_TAG . ' @' . $xref . '@(\n[5-9].*)*/', '', $gedrec);
        $gedrec = preg_replace('/\n5 ' . Gedcom::REGEX_TAG . ' @' . $xref . '@(\n[6-9].*)*/', '', $gedrec);

        return $gedrec;
    }

}

return new BulkDeleteModule();
