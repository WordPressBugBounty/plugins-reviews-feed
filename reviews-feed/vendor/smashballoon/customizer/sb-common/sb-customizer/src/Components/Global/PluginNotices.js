import { useContext, useState } from 'react';
import SbUtils from '../../Utils/SbUtils'
import Button from '../Common/Button';

const PluginNotices = ( props ) => {
    const currentContext = SbUtils.getCurrentContext();
    const {
        globalNotices
    } = useContext( currentContext ) ;
    const [ currentNoticeIndex, setCurrentNoticeIndex  ] = useState( 0 );
    const [ currentNotice, setCurrentNotice  ] = useState( globalNotices?.pluginNotices[currentNoticeIndex] || false);


    const printNoticeDescription = (description) => {
        if (Array.isArray(description)) {
            return description.join("<br/><br/>")
        }
        return description;
    }

    return (
        currentNotice &&
        <div
            className='sb-inplugin-notice-ctn sb-full-wrapper sb-fs'
            data-type={currentNotice.type}
        >
            <div className='sb-inplugin-notice-content sb-fs'>
                <div className='sb-inplugin-notice-icon'>
                    <span className='sb-inplugin-notice-svg'>{SbUtils.printIcon( 'exclamation', '', false, 16 )}</span>
                </div>
                <div className='sb-inplugin-notice-text'>
                    <h4 className='sb-inplugin-notice-heading sb-h4'>{ currentNotice.heading }</h4>
                    <span className='sb-text sb-dark2-tex' dangerouslySetInnerHTML={{__html:  printNoticeDescription(currentNotice.description)  }}></span>
                    <div className='sb-inplugin-notice-action'>
                        {
                            currentNotice?.actions && currentNotice?.actions.map( (action, key) => {
                                return (
                                    <Button
                                        key={key}
                                        size='medium'
                                        type={ action?.type }
                                        text={ action?.text }
                                        link={ action?.link }
                                    />
                                )
                            } )
                        }
                    </div>

                </div>
            </div>
        </div>
    )

}

export default PluginNotices;
