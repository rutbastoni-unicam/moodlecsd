//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/messages/>.
import {call as fetchMany} from 'core/ajax';
import ModalEvents from 'core/modal_events';
import ModalSaveCancel from 'core/modal_save_cancel';
import {get_string as getString} from 'core/str';
import Templates from 'core/templates';
import * as CourseEvents from 'core_course/events';
import $ from 'jquery';

/**
 * Handle progress game from Unity interface to Moodle
 *
 * @module     mod_webgl
 */
window.mod_webgl_plugin = {
    initted: false,
    trackGameViewed: () => {},
    trackGameProgress: () => {}
};

/**
 * @typedef {Object} ProgressData
 * @property {number} score - achieved game score
 * @property {number} completedLevels - number of completed game levels
 * @property {boolean} puzzleSolved - if the puzzle of this game has been solved
 */

export const init = () => {
    const showGameCompleteDialog = async () => {
        const modalbacktocourse = await ModalSaveCancel.create({
            title: getString('gamecompletedialog', 'mod_webgl'),
            body: getString('gamecompletedialogbody', 'mod_webgl'),
            buttons: {
                cancel: getString('gamecompletedialogcancel', 'mod_webgl'),
                save: getString('gamecompletedialogsave', 'mod_webgl')
            }
        });

        // Remove default click listener outside the modal that makes it close;
        // we want the user explicitly click a button to confirm his choice
        modalbacktocourse.getRoot().off('click');

        modalbacktocourse.getRoot().on(ModalEvents.save, () => {
            $('#mod_webgl_course_url').submit();
        });
        modalbacktocourse.show();
    };

    const handleCompletionData = async (completiondata) => {
        // Replace activity completion info
        const activityInfosBlock = $('.activity-information');
        if (activityInfosBlock.length) {
            const renderObject = await Templates.renderForPromise('core_course/activity_info', completiondata);
            await Templates.replaceNode(activityInfosBlock[0], renderObject.html, renderObject.js);
        }

        if (completiondata.overallcomplete) {
            showGameCompleteDialog();
        }
    };

    /**
     * Call to internal API to set this game as viewed
     */
    const setGameLoaded = async () => {
        const webglid = $('.webgl-iframe-content-loader').data('webgl');

        const response = await fetchMany([{
            methodname: 'mod_webgl_signal_game_loaded',
            args: {'webglid': webglid}
        }])[0];

        if (!response) {
            window.console.error('Error setting webgl ' + webglid + ' as viewed');
        } else {
            handleCompletionData(response.completiondata);
            window.console.log(response);
        }

    };

    /**
     *
      * @param {ProgressData} progressData
     * @returns {Promise<void>}
     */
    const setGameProgress = async (progressData) => {
        const webglid = $('.webgl-iframe-content-loader').data('webgl');
        window.console.log('Setting progress data object');
        window.console.log(progressData);

        //public static function signal_game_progress($webglid, $score, $completedlevels, $puzzlesolved) {
        const score = progressData?.score ? progressData.score : 0;
        const completedLevels = progressData?.completedLevels ? progressData.completedLevels : 0;
        const puzzleSolved = progressData?.puzzleSolved ? 1 : 0;

        const response = await fetchMany([{
            methodname: 'mod_webgl_signal_game_progress',
            args: {'webglid': webglid, 'score': score, 'completedlevels': completedLevels, 'puzzlesolved': puzzleSolved}
        }])[0];

        if (!response) {
            window.console.error('Error setting webgl ' + webglid + ' progress data');
        } else {
            handleCompletionData(response.completiondata);
            window.console.log(response);
        }

    };

    const checkWebglIframeLoaded = () => {
        const unityFrame = $('.webgl-iframe-content-loader iframe');
        if(unityFrame.length < 1) {
            // No proper Unity framework installed - maybe page is still loading
            setTimeout(checkWebglIframeLoaded, 250);
            return;
        }

        const unityLoadingBar = unityFrame[0].contentDocument.querySelector("#unity-loading-bar");
        if (!unityLoadingBar) {
            // No proper Unity framework installed - maybe page is still loading
            setTimeout(checkWebglIframeLoaded, 250);
            return;
        }

        const loadingBarStyle = unityLoadingBar.style.display;

        // Unity loading bar still visible - game still not played
        if (loadingBarStyle != 'none') {
            setTimeout(checkWebglIframeLoaded, 250);
            return;
        }

        // Unity game loaded - track activity as viewed
        window.console.error('Game loaded, track it');
        setGameLoaded();
    };

    window.mod_webgl_plugin.trackGameViewed = setGameLoaded;
    window.mod_webgl_plugin.trackGameProgress = setGameProgress;

    window.mod_webgl_plugin.initted = true;

    // Listen for events triggered by Webgl components (alternative to mod_webgl_plugin API)
    window.addEventListener("gameLoaded", () => {
        window.console.log("gameLoaded event received");
        setGameLoaded();
    });

    window.addEventListener("gameProgress", (event) => {
        window.console.log("gameProgress event received:", event.detail);
        // Gestisci il progresso del gioco qui
        setGameProgress(event.detail);
    });

    // Listen for toggled manual completion states of activities.
    document.addEventListener(CourseEvents.manualCompletionToggled, (e) => {
        const withAvailability = parseInt(e.detail.withAvailability);
        if (!withAvailability && e.detail.completed) {
            // In case of availability params, this state is already handled by core
            // Otherwise, if the course is flagged as completed, it shows the game completed dialog
            showGameCompleteDialog();
        }
    });

    // Autodetect game loaded
    checkWebglIframeLoaded();

    // Listen for events triggered inside the Webgl game
    const unityFrame = $('.webgl-iframe-content-loader iframe');
    if(unityFrame.length) {
        const iframe = unityFrame[0];
        iframe.addEventListener('load', () => {
            const iframeWindow = iframe.contentWindow;

            iframeWindow.addEventListener("gameLoaded", () => {
                window.console.log("gameLoaded event received from Webgl frame");
                setGameLoaded();
            });

            iframeWindow.addEventListener("gameProgress", (event) => {
                window.console.log("gameProgress event received from Webgl frame:", event.detail);
                // Gestisci il progresso del gioco qui
                setGameProgress(event.detail);
            });
        });
    }
};