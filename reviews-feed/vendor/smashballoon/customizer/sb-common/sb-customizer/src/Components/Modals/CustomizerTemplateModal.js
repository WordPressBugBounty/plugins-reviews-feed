import { useContext, useState } from 'react'
import { __ } from '@wordpress/i18n'
import SbUtils from '../../Utils/SbUtils'
import Button from '../Common/Button';
import Template from '../Common/Template';
import Notice from '../Global/Notice';
import FeedEditorContext from '../Context/FeedEditorContext';

const CustomizerTemplateModal = ( props ) => {

    const [ choosedTemplate, setChoosedTemplate ] = useState( null );
    const {
        editorTopLoader,
        editorFeedData,
        editorFeedSettings,
        editorNotification,
        sbCustomizer,
        isPro,
        upsellModal
    } = useContext( FeedEditorContext ) || {};

    const updateTemplate = () => {
        const newTemplate = choosedTemplate !== null ? choosedTemplate : props.selectedTemplate;
        editorFeedSettings.feedSettings.feedTemplate = newTemplate;
        props.onUpdateTemplate( newTemplate );

        //Ajax Call to get Template Settings
        const formData = {
            action : 'sbr_feed_saver_manager_fly_preview',
            isFeedTemplatesPopup : true,
            feedID : editorFeedData.feedData.feed_info.id,
            feedName : editorFeedData.feedData.feed_info.feed_name,
            previewSettings : JSON.stringify( editorFeedSettings.feedSettings )
        },
        notificationsContent = {
            success : {
                icon : 'success',
                text : __( 'Templates updated', 'sb-customizer' )
            }
        }

        SbUtils.ajaxPost(
            sbCustomizer.ajaxHandler,
            formData,
            ( data ) => { //Call Back Function
                if( data?.settings ){
                    editorFeedSettings.setFeedSettings( data.settings );
                }
                if( data?.sourcesList ){
                    editorFeedData?.setFeedData({
                        ...editorFeedData?.feedData,
                        sourcesList : data?.sourcesList
                    })
                }
            },
            editorTopLoader,
            editorNotification,
            notificationsContent
        )
        setTimeout(() => {

        }, 500);
    }


    return (
        <div className='sb-feedtemplates-modal sb-fs'>
            <div className='sb-feedtemplates-modal-content'>
                <div className='sb-feedtemplates-modal-header'>
                    <h3 className='sb-h3'>{ __( 'Select another template', 'sb-customizer' ) }</h3>
                    <div className='sb-feedtemplates-modal-actions'>
                        <Button
                            icon='close'
                            iconSize='12'
                            size='medium'
                            type='secondary'
                            onClick={ () => {
                                props?.onCancel &&
                                    props.onCancel()
                            }}
                            text={ __( 'Cancel', 'sb-customizer' ) }
                        />
                        <Button
                            icon='success'
                            iconSize='13'
                            size='medium'
                            type='primary'
                            onClick={ () => {
                                updateTemplate()
                            } }
                            text={ __( 'Update', 'sb-customizer' ) }
                        />
                    </div>
                </div>
                <Notice
                    icon='notice'
                    text={ __( 'Changing a template will override your layout, header and button settings', 'sb-customizer' ) }
                />
                <div className='sb-templates-list  sb-fs'>
                {
                    props.templatesList.map( ( template, templateInd ) => {
                        const isTemplatePro = SbUtils.checkSettingIsPro( template.upsellModal ) !== false;
                        return(
                            <Template
                                key={ templateInd }
                                type={ template.type }
                                title={ template.title }
                                isTemplatePro={ isTemplatePro }
                                isChecked={ ( choosedTemplate !== null && template.type === choosedTemplate) || ( choosedTemplate === null && template.type === props.selectedTemplate )}
                                onClick={ () => {
                                    if( isTemplatePro === false  ){
                                        setChoosedTemplate( template.type )
                                    }else{
                                        SbUtils.openUpsellModal( template.upsellModal, upsellModal )
                                    }
                                } }
                            />
                        )
                    } )
                }
            </div>
            </div>
        </div>
    )
}

export default CustomizerTemplateModal;