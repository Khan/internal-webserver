import os

from django import forms
from django.utils.encoding import smart_unicode
from django.utils.translation import ugettext as _

from reviewboard.diffviewer.diffutils import DEFAULT_DIFF_COMPAT_VERSION
from reviewboard.diffviewer.models import DiffSet, FileDiff
from reviewboard.scmtools.core import PRE_CREATION, UNKNOWN, FileNotFoundError


class EmptyDiffError(ValueError):
    pass


class NoBaseDirError(ValueError):
    pass


class UploadDiffForm(forms.Form):
    basedir = forms.CharField(
        label=_("Base Directory"),
        help_text=_("The absolute path in the repository the diff was "
                    "generated in."))
    path = forms.FileField(
        label=_("Diff"),
        help_text=_("The new diff to upload."))
    parent_diff_path = forms.FileField(
        label=_("Parent Diff"),
        help_text=_("An optional diff that the main diff is based on. "
                    "This is usually used for distributed revision control "
                    "systems (Git, Mercurial, etc.)."),
        required=False)

    # Extensions used for intelligent sorting of header files
    # before implementation files.
    HEADER_EXTENSIONS = ["h", "H", "hh", "hpp", "hxx", "h++"]
    IMPL_EXTENSIONS   = ["c", "C", "cc", "cpp", "cxx", "c++", "m", "mm", "M"]

    def __init__(self, repository, *args, **kwargs):
        forms.Form.__init__(self, *args, **kwargs)
        self.repository = repository

        if self.repository.get_scmtool().get_diffs_use_absolute_paths():
            # This SCMTool uses absolute paths, so there's no need to ask
            # the user for the base directory.
            del(self.fields['basedir'])

    def create(self, diff_file, parent_diff_file=None, diffset_history=None):
        tool = self.repository.get_scmtool()

        # Grab the base directory if there is one.
        if not tool.get_diffs_use_absolute_paths():
            try:
                basedir = smart_unicode(self.cleaned_data['basedir'].strip())
            except AttributeError:
                raise NoBaseDirError(
                    _('The "Base Diff Path" field is required'))
        else:
            basedir = ''

        # Parse the diff
        files = list(self._process_files(
            diff_file, basedir, check_existance=(not parent_diff_file)))

        if len(files) == 0:
            raise EmptyDiffError(_("The diff file is empty"))

        # Sort the files so that header files come before implementation.
        files.sort(cmp=self._compare_files, key=lambda f: f.origFile)

        # Parse the parent diff
        parent_files = {}

        # This is used only for tools like Mercurial that use atomic changeset
        # IDs to identify all file versions but not individual file version
        # IDs.
        parent_changeset_id = None

        if parent_diff_file:
            # If the user supplied a base diff, we need to parse it and
            # later apply each of the files that are in the main diff
            for f in self._process_files(parent_diff_file, basedir,
                                         check_existance=True):
                parent_files[f.origFile] = f

                # Store the original changeset ID if we have it; this should
                # be the same for all files.
                if f.origChangesetId:
                    parent_changeset_id = f.origChangesetId

        diffset = DiffSet(name=diff_file.name, revision=0,
                          basedir=basedir,
                          history=diffset_history,
                          diffcompat=DEFAULT_DIFF_COMPAT_VERSION)
        diffset.repository = self.repository
        diffset.save()

        for f in files:
            if f.origFile in parent_files:
                parent_file = parent_files[f.origFile]
                parent_content = parent_file.data
                source_rev = parent_file.origInfo
            else:
                parent_content = ""

                if (tool.diff_uses_changeset_ids and
                    parent_changeset_id and
                    f.origInfo != PRE_CREATION):
                    source_rev = parent_changeset_id
                else:
                    source_rev = f.origInfo

            dest_file = os.path.join(basedir, f.newFile).replace("\\", "/")

            if f.deleted:
                status = FileDiff.DELETED
            elif f.moved:
                status = FileDiff.MOVED
            else:
                status = FileDiff.MODIFIED

            filediff = FileDiff(diffset=diffset,
                                source_file=f.origFile,
                                dest_file=dest_file,
                                source_revision=smart_unicode(source_rev),
                                dest_detail=f.newInfo,
                                diff=f.data,
                                parent_diff=parent_content,
                                binary=f.binary,
                                status=status)
            filediff.save()

        return diffset

    def _process_files(self, file, basedir, check_existance=False):
        tool = self.repository.get_scmtool()

        for f in tool.get_parser(file.read()).parse():
            f2, revision = tool.parse_diff_revision(f.origFile, f.origInfo,
                                                    f.moved)

            if f2.startswith("/"):
                filename = f2
            else:
                filename = os.path.join(basedir, f2).replace("\\", "/")

            # FIXME: this would be a good place to find permissions errors
            if (revision != PRE_CREATION and
                revision != UNKNOWN and
                not f.binary and
                not f.deleted and
                not f.moved and
                (check_existance and
                 not tool.file_exists(filename, revision))):
                raise FileNotFoundError(filename, revision)

            f.origFile = filename
            f.origInfo = revision

            yield f

    def _compare_files(self, filename1, filename2):
        """
        Compares two files, giving precedence to header files over source
        files. This allows the resulting list of files to be more
        intelligently sorted.
        """
        if filename1.find('.') != -1 and filename2.find('.') != -1:
            basename1, ext1 = filename1.rsplit('.', 1)
            basename2, ext2 = filename2.rsplit('.', 1)

            if basename1 == basename2:
                if ext1 in self.HEADER_EXTENSIONS and \
                   ext2 in self.IMPL_EXTENSIONS:
                    return -1
                elif ext1 in self.IMPL_EXTENSIONS and \
                     ext2 in self.HEADER_EXTENSIONS:
                    return 1

        return cmp(filename1, filename2)
