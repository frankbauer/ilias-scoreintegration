# ILIAS Quode Question Score Integration Plugin

**Author**:   Frank Bauer <frank.bauer@fau.de>

**Version**:  1.3.0

**Company**:  Friedrich-Alexander-Universität, Visual Computing

**Supports**: ILIAS 9

## License
THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.

## Installation
1. Copy the `
CodeQuestionScoreIntegration` directory to your ILIAS installation at the following path 
(create subdirectories, if neccessary):
`Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/CodeQuestionScoreIntegration`

2. You need to update the classmap after installing any Plugin. In the folder of your ILIAS installation, call `composer install --no-dev` to  regenerate the class map and build the static artifacts map. 
3. Go to Administration > Plugins. If you do not see the plugin, your static artifact map needs to be rebuilt. You can rebuild those by calling `php setup/cli.php build-artifacts` in the folder of your ILIAS installation.


4. Choose **Update** for the `CodeQuestionScoreIntegration` plugin
5. Choose **Activate** for the `CodeQuestionScoreIntegration` plugin
6. Choose **Refresh** for the `CodeQuestionScoreIntegration` plugin languages

There is nothing to configure for this plugin.

## Version History
### Version 1.3.0
* Export Cloze Solutions
* Add Checkbox to aut mark the uploaded manual scoring result as finished
### Version 1.2.3
* Moved Plugin Code to Ilias 7
### Version 1.2.0
* Support for new CodeQuestion Structure form the 1.2.x series
### Version 1.0.3
* Bugfix for ordering export
### Version 1.0.2
* Exporting all code blocks to seperate files.
* Export json for vertical/horizontal ordering question type
