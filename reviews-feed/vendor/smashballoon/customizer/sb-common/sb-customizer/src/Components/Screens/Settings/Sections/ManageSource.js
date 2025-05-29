import { __ } from '@wordpress/i18n'
import { useContext } from 'react';
import SbUtils from '../../../../Utils/SbUtils';
import SettingsScreenContext from '../../../Context/SettingsScreenContext';
import SelectSourcesSection from "../../CreationProcess/SelectSourcesSection";

const ManageSource = ( props ) => {
    const {
        sbCustomizer,
        editorNotification,
        editorConfirmDialog,
        sources,
        freeRet,
        editorTopLoader,
        fbModal
    } = useContext( SettingsScreenContext) ;

    const deleteSource = ( source ) => {
        const confirmDialogInfo = {
                active : true,
                heading : `${ __('Delete ', 'sb-customizer') + '"'+source.name +'"?'  }`,
                description : __( 'If you delete this source then new posts can no longer be retrieved for feeds using this source.', 'sb-customizer' ),
                confirm : {}
        },
        notificationsContent = {
            success : {
                icon : 'success',
                text : __( 'Source Deleted!', 'sb-customizer' )
            }
        };

        confirmDialogInfo.confirm.onConfirm = () => {
            const formData = {
                action : 'sbr_feed_saver_manager_delete_source',
                sourceID : source.id,
                sourceAccountID : source.account_id,
                sourceProvider : source.provider
            }
            SbUtils.ajaxPost(
                sbCustomizer.ajaxHandler,
                formData,
                ( data ) => { //Call Back Function
                    sources.setSourcesList( data.sourcesList )
                    if (data?.freeRetrieverData) {
                        freeRet.setFreeRetrieverData(data?.freeRetrieverData)
                    }
                },
                editorTopLoader,
                editorNotification,
                notificationsContent
            )
        }
        editorConfirmDialog.setConfirmDialog( confirmDialogInfo )

    }


    return (
        <>
            <SelectSourcesSection
                context='settingspage'
                deleteSource={ ( source ) => {
                    deleteSource( source )
                } }
            />
        </>
    )
}

export default ManageSource;